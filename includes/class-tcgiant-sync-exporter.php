<?php
/**
 * Exporter Logic
 *
 * Manages pushing WooCommerce products to eBay as new or updated listings.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Exporter class
 */
class TCGiant_Sync_Exporter {

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

	/**
	 * Export state option key.
	 */
	const EXPORT_STATE_OPTION = 'tcgiant_export_state';

	/**
	 * Action Scheduler hook for processing a single export job.
	 */
	const PUSH_ACTION = 'tcgiant_export_push_product';

	/**
	 * What a push is called in the record PHP leaves when it kills one -
	 * "Push stopped by a PHP fatal error ..." - and in the option that record
	 * is kept under.
	 */
	const PUSH_JOB = 'Push';

	/**
	 * Seconds a push may say it is still pushing before the screens stop
	 * believing it.
	 *
	 * One push is one eBay call and a product save: well under a minute, even
	 * with eBay slow to answer. A push the host killed outright says "pushing"
	 * for ever, because nothing in the request lived to say otherwise, and
	 * Action Scheduler only notices five minutes later at the soonest - on a
	 * site with little traffic, much later than that. Fifteen minutes is well
	 * past any push that is really still going.
	 */
	const PUSH_STALE_AFTER = 900;

	/**
	 * What a product whose push did not finish says, after that push's own
	 * words, of a push after it that made nothing: refused by eBay, or
	 * stopped by an error before eBay made anything. Each such push replaces
	 * what the one before it said here, rather than adding to it.
	 */
	const REFUSED_AFTER_UNFINISHED = ' The push after it was refused: ';

	/**
	 * The push this request is working on, while it is: which product, what
	 * it is called, the listing it was linked to when it began and whether
	 * that listing had ended, and the listing eBay created for it once that
	 * is known.
	 *
	 * Kept here, ready, so that if PHP kills the push the record of it can be
	 * written from what is already in memory.
	 *
	 * @var array|null
	 */
	private static $in_flight = null;

	/**
	 * The description the last build_item_xml() put in its item: the bytes, the
	 * template and version, and the hashes recorded once eBay accepts it.
	 *
	 * Reset at the top of every build. One exporter can push several products
	 * in one Action Scheduler run, and an item that returned early must never
	 * report the product before it.
	 *
	 * @var array|null
	 */
	private $last_render = null;

	/*
	 * Kept for anyone outside the plugin who reached for these here.
	 *
	 * The eBay vocabulary and the listing-identity helpers moved to
	 * TCGiant_Sync_Catalog and TCGiant_Sync_Listing_Link in 3.14.1, because
	 * editions that ship no exporter need them. Nothing in the plugin uses
	 * these names any more. A theme snippet or a site's own mu-plugin might,
	 * and an update must not fatal a site that was working. They forward.
	 */
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::CONDITIONS. */
	const CONDITIONS            = TCGiant_Sync_Catalog::CONDITIONS;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::CONDITIONS_MEDIA_ONLY. */
	const CONDITIONS_MEDIA_ONLY = TCGiant_Sync_Catalog::CONDITIONS_MEDIA_ONLY;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::GRADERS_TCG. */
	const GRADERS_TCG           = TCGiant_Sync_Catalog::GRADERS_TCG;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::GRADERS_COINS. */
	const GRADERS_COINS         = TCGiant_Sync_Catalog::GRADERS_COINS;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::GRADES_TCG. */
	const GRADES_TCG            = TCGiant_Sync_Catalog::GRADES_TCG;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::GRADES_COINS. */
	const GRADES_COINS          = TCGiant_Sync_Catalog::GRADES_COINS;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::UNGRADED_TCG. */
	const UNGRADED_TCG          = TCGiant_Sync_Catalog::UNGRADED_TCG;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::UNGRADED_COINS. */
	const UNGRADED_COINS        = TCGiant_Sync_Catalog::UNGRADED_COINS;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::CATEGORIES. */
	const CATEGORIES            = TCGiant_Sync_Catalog::CATEGORIES;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::LISTING_TYPES. */
	const LISTING_TYPES         = TCGiant_Sync_Catalog::LISTING_TYPES;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::LISTING_DURATIONS. */
	const LISTING_DURATIONS     = TCGiant_Sync_Catalog::LISTING_DURATIONS;
	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::DURATIONS_BY_TYPE. */
	const DURATIONS_BY_TYPE     = TCGiant_Sync_Catalog::DURATIONS_BY_TYPE;

	/** @deprecated 3.14.1 Use TCGiant_Sync_Catalog::get_categories(). */
	public static function get_categories() {
		return TCGiant_Sync_Catalog::get_categories();
	}

	/** @deprecated 3.14.1 Use TCGiant_Sync_Listing_Link::listing_identity_meta_keys(). */
	public static function listing_identity_meta_keys() {
		return TCGiant_Sync_Listing_Link::listing_identity_meta_keys();
	}

	/** @deprecated 3.14.1 Use TCGiant_Sync_Listing_Link::duplicate_exclude_meta(). */
	public static function duplicate_exclude_meta( $exclude ) {
		return TCGiant_Sync_Listing_Link::duplicate_exclude_meta( $exclude );
	}

	/** @deprecated 3.14.1 Use TCGiant_Sync_Listing_Link::on_product_duplicated(). */
	public static function on_product_duplicated( $duplicate, $original = null ) {
		TCGiant_Sync_Listing_Link::on_product_duplicated( $duplicate, $original );
	}

	/** @deprecated 3.14.1 Use TCGiant_Sync_Listing_Link::on_third_party_duplicate(). */
	public static function on_third_party_duplicate( $new_post_id, $post = null ) {
		TCGiant_Sync_Listing_Link::on_third_party_duplicate( $new_post_id, $post );
	}

	/** @deprecated 3.14.1 Use TCGiant_Sync_Listing_Link::unlink_product_from_ebay(). */
	public static function unlink_product_from_ebay( $product_id, $reason = 'unlinked' ) {
		TCGiant_Sync_Listing_Link::unlink_product_from_ebay( $product_id, $reason );
	}

	/** @deprecated 3.14.1 Use TCGiant_Sync_Listing_Link::find_products_sharing_item_id(). */
	public static function find_products_sharing_item_id( $product_id, $ebay_item_id ) {
		return TCGiant_Sync_Listing_Link::find_products_sharing_item_id( $product_id, $ebay_item_id );
	}

	/**
	 * eBay categories that require ConditionDescriptors — Trading Cards.
	 */
	const DESCRIPTOR_CATEGORIES_TCG = array(
		'183050', // Trading Card Games
		'183454', // CCG Individual Cards (Dragon Ball Super, etc.)
		'261328', // Sports Trading Card Singles
		'2536',   // Trading Cards
		'261068', // Non-Sport Trading Card Games
		'180006', // Pokémon Individual Cards
		'176055', // Magic: The Gathering Cards
		'69243',  // Yu-Gi-Oh! Individual Cards
		'183446', // Disney Lorcana
		'185089', // One Piece Card Game
	);

	/**
	 * eBay categories that require ConditionDescriptors — Coins.
	 */
	const DESCRIPTOR_CATEGORIES_COINS = array(
		'253',   // Coins: US
		'256',   // Coins: World
		'3377',  // Coins: Canada
		'4733',  // Coins: Ancient
		'18466', // Coins: Medieval
	);

	/**
	 * Maps text-based grade aliases to their canonical 'LETTER NUM' Sheldon values.
	 * Used during XML generation to resolve human-readable labels to eBay descriptor IDs.
	 */
	const TEXT_GRADE_ALIASES = array(
		'Superb Gem BU (≈MS 67)'     => 'MS/PR 67',
		'Gem BU (≈MS 65)'            => 'MS/PR 65',
		'Choice BU (≈MS 63)'         => 'MS/PR 63',
		'Superb Gem Proof (≈PR 67)'  => 'MS/PR 67',
		'Gem Proof (≈PR 65)'         => 'MS/PR 65',
		'Choice Proof (≈PR 63)'      => 'MS/PR 63',
		'Choice AU (≈AU 55)'         => 'AU 55',
		'Choice XF (≈XF 45)'         => 'XF 45',
		'Choice VF (≈VF 35)'         => 'VF 35',
	);

	/**
	 * Letter grade label => eBay conditionDescriptorValueId.
	 * Coins — Descriptor Name 3.
	 */
	const LETTER_GRADES_COINS = array(
		'MS/PR' => '18',
		'AU'    => '19',
		'XF'    => '20',
		'VF'    => '46',
		'F'     => '47',
		'VG'    => '48',
		'G'     => '49',
		'AG'    => '50',
		'FR'    => '51',
		'P'     => '52',
	);

	/**
	 * Numeric grade label => eBay conditionDescriptorValueId.
	 * Coins — Descriptor Name 4.
	 */
	const NUMERIC_GRADES_COINS = array(
		'70' => '32',
		'69' => '25',
		'68' => '26',
		'67' => '53',
		'66' => '54',
		'65' => '55',
		'64' => '56',
		'63' => '57',
		'62' => '58',
		'61' => '59',
		'60' => '60',
		'58' => '27',
		'55' => '28',
		'53' => '29',
		'50' => '61',
		'45' => '30',
		'40' => '31',
		'35' => '62',
		'30' => '63',
		'25' => '64',
		'20' => '65',
		'15' => '66',
		'12' => '67',
		'10' => '68',
		'8'  => '69',
		'6'  => '70',
		'4'  => '71',
		'3'  => '72',
		'2'  => '73',
		'1'  => '74',
	);

	/**
	 * The per-site daily cap on bulk pushes: its own option, never a key of
	 * tcgiant_sync_ebay_settings, whose forms rewrite the whole option and
	 * drop what they do not list.
	 *
	 * Every shop shares one eBay call budget, 50,000 a day, and each push costs
	 * 1 to 13 calls. Until 3.27.8 only the fleet-wide stop at 80% limited a
	 * bulk push, so a shop applying a new listing template by pushing its
	 * whole catalogue could spend the day for everyone.
	 */
	const BULK_PUSH_CAP_OPTION  = 'tcgiant_sync_bulk_push_cap';
	const BULK_PUSH_CAP_DEFAULT = 2000;
	const BULK_PUSH_CAP_MIN     = 50;
	const BULK_PUSH_CAP_MAX     = 20000;

	/** How many products bulk pushes have queued today, and which day that is. */
	const BULK_PUSHED_OPTION = 'tcgiant_sync_bulk_pushed';

	/**
	 * The products a bulk push counted today and queued, whichever screen
	 * pushed them (the products list, the coin update, the Listings screen):
	 * {day, ids}. A new bulk push cancels the ones still waiting and gives
	 * them back to the day's count.
	 */
	const BULK_WAITING_OPTION = 'tcgiant_sync_bulk_waiting';

	/**
	 * Products the last bulk_push_products() call could not queue, because
	 * the day's cap was reached.
	 *
	 * @var int
	 */
	private $last_bulk_refused = 0;

	/**
	 * Pushes still waiting from the last bulk push, which the last
	 * bulk_push_products() call replaced and gave back to the day's count.
	 *
	 * @var int
	 */
	private $last_bulk_replaced = 0;

	/**
	 * Maximum eBay title length (hard limit).
	 */
	const MAX_TITLE_LENGTH = 80;

	/**
	 * Maximum photos a listing is sent (PictureDetails.PictureURL).
	 *
	 * eBay's Trading API takes up to 24 in almost every category, free; until
	 * this was 12, so a product's 13th to 24th photos never reached eBay. A
	 * variation's own pictures (VariationSpecificPictureSet) are limited to 12
	 * by eBay, but a push sends none: a variable product's photos go in the
	 * listing's PictureDetails like any other's.
	 */
	const MAX_IMAGES = 24;

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
	 * Constructor — register Action Scheduler callbacks.
	 */
	public function __construct() {
		add_action( self::PUSH_ACTION, array( $this, 'process_single_push' ), 10, 1 );

		// A push the host kills writes nothing at all. A shop pushed three
		// products, the first was killed after eBay had created its listing but
		// before the product was given the item number, and the log said
		// nothing about it - the product simply sat there unlinked, and the
		// batch reported itself complete.
		//
		// Action Scheduler is the one thing that does notice, and these are its
		// hooks for it (woocommerce/action-scheduler):
		//
		// - action_scheduler_failed_action ( $action_id, $timeout ): from the
		//   queue cleaner, in a later request, for an action still marked as
		//   running five minutes on. A request killed outright - by the host's
		//   own time limit or a process reaper - is only ever found this way.
		// - action_scheduler_unexpected_shutdown ( $action_id, $error ): from
		//   its fatal error monitor, in the dying request, after a PHP fatal.
		// - action_scheduler_failed_execution ( $action_id, $e, $context ): the
		//   action threw.
		//
		// It has no action_scheduler_failed_old_action. The cleaner's only other
		// failure hook, action_scheduler_failed_old_action_deletion, is about
		// deleting old actions, not running them.
		add_action( 'action_scheduler_failed_action', array( $this, 'on_push_action_failed' ), 10, 2 );
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'on_push_action_shutdown' ), 10, 2 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'on_push_action_threw' ), 10, 2 );

		// Each push asks whether its batch is over while Action Scheduler still
		// counts it as running - and so is any push running beside it. Two queue
		// runners finishing a batch's last two pushes at once each saw the
		// other's still running and left it, and nothing ever asked again. Once
		// a queue run is over, its own pushes are marked done.
		add_action( 'action_scheduler_after_process_queue', array( $this, 'recheck_export_completion' ) );
	}

	// -------------------------------------------------------------------------
	// State Management
	// -------------------------------------------------------------------------

	/**
	 * Get the current export state.
	 *
	 * @return array Export state data.
	 */
	public static function get_export_state() {
		return get_option( self::EXPORT_STATE_OPTION, array(
			'status'          => 'idle',   // idle, scheduling, queued, pushing, complete, incomplete, error
			'total_queued'    => 0,
			'total_pushed'    => 0,
			'total_errors'    => 0,
			'started_at'      => '',
			'last_activity'   => '',
			'last_completed'  => '',
			'last_item_title' => '',
		) );
	}

	/**
	 * Update export state.
	 *
	 * @param array $updates Key-value pairs to merge into current state.
	 */
	public static function update_export_state( $updates ) {
		$state = self::get_export_state();
		$state = array_merge( $state, $updates );
		$state['last_activity'] = current_time( 'mysql' );
		update_option( self::EXPORT_STATE_OPTION, $state );
	}

	/**
	 * Forget this request's copy of the export state, so the next read is the
	 * database's.
	 *
	 * WordPress keeps an autoloaded option for the length of a request. A
	 * request that has been at work for a while - scheduling a thousand
	 * pushes, or running a queue - holds the state as it was when it began,
	 * and writing that back would undo what the pushes in other requests have
	 * counted since.
	 */
	private static function forget_cached_export_state() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( self::EXPORT_STATE_OPTION, 'options' );
		}
	}

	// -------------------------------------------------------------------------
	// Public Entry Points
	// -------------------------------------------------------------------------

	/**
	 * Queue a single WooCommerce product for export to eBay.
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return bool True if queued, false if product not found.
	 */
	public function push_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			TCGiant_Sync_Logger::error( sprintf( 'Export: Product ID %d not found.', $product_id ) );
			return false;
		}

		as_enqueue_async_action(
			self::PUSH_ACTION,
			array( 'product_id' => $product_id ),
			'tcgiant_sync_group'
		);

		// Clear any previous error so it doesn't linger during the new attempt.
		//
		// Not one saying an earlier push did not finish. That push may have
		// made a listing nothing here points to, and the product must go on
		// saying so until a push of it finishes or Link gives it that listing:
		// what it says is what keeps it out of Clear, Auto-Relist, Relist, the
		// bulk pushes and "Remove sold products". Written over at once, it was
		// gone for good when the push queued here never finished - cancelled
		// by any later bulk push, which cancels every push still waiting, or
		// refused before it reached eBay.
		if ( 'failed' !== (string) $product->get_meta( '_ebay_export_status' ) ) {
			$product->update_meta_data( '_ebay_export_status', 'queued' );
			$product->update_meta_data( '_ebay_export_error', '' );
		}
		// Each save here fires other plugins' save hooks: see
		// TCGiant_Sync_Runtime::guard_loops().
		TCGiant_Sync_Runtime::guard_loops();
		try {
			$product->save();
		} finally {
			TCGiant_Sync_Runtime::unguard_loops();
		}

		TCGiant_Sync_Logger::log( sprintf( 'Export: Queued "%s" (WC #%d) for push to eBay.', $product->get_name(), $product_id ) );
		return true;
	}

	/**
	 * Queue multiple WooCommerce products for export to eBay.
	 *
	 * @param int[] $product_ids Array of WooCommerce Product IDs.
	 * @return int Number of products successfully queued.
	 */
	public function bulk_push_products( $product_ids ) {
		$this->last_bulk_refused  = 0;
		$this->last_bulk_replaced = 0;

		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return 0;
		}

		$batch = array();
		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			if ( $product_id > 0 ) {
				$batch[] = $product_id;
			}
		}

		if ( empty( $batch ) ) {
			return 0;
		}

		// The day's cap on bulk pushes, before anything is touched: a push
		// refused by it leaves the queue exactly as it was. A bulk push still
		// waits from the last one replaces nothing yet, but once this one is
		// queued it is cancelled below, so what it counted today is given back.
		$waiting = self::waiting_bulk_pushes();
		$allowed = self::take_bulk_push_allowance( count( $batch ), $waiting );
		$this->last_bulk_refused  = count( $batch ) - $allowed;
		$this->last_bulk_replaced = $allowed > 0 ? $waiting : 0;
		if ( $this->last_bulk_replaced > 0 ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Bulk push: %d push(es) still waiting from the last bulk push were replaced by this one, and no longer count towards today\'s limit.',
				$this->last_bulk_replaced
			) );
		}
		if ( $this->last_bulk_refused > 0 ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Bulk push: %1$d product(s) queued and %2$d not, because this site\'s limit of %3$d bulk pushes a day is reached. The limit resets at midnight UTC; it is set in TCGiant Sync > Listing Templates > Template settings.',
				$allowed,
				$this->last_bulk_refused,
				self::bulk_push_cap()
			) );
		}
		$batch = array_slice( $batch, 0, $allowed );

		$queued = count( $batch );
		if ( 0 === $queued ) {
			return 0;
		}

		// Clear any existing pending export jobs to prevent stacking.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::PUSH_ACTION, null, 'tcgiant_sync_group' );
		}

		// The batch's figures go in before any of its pushes exist. Written
		// after them, as they were, a push that ran at once - the first is due
		// immediately, and a thousand take seconds to schedule - counted itself
		// in figures this then reset, so a batch that had pushed everything
		// came up one short; and a request killed while scheduling left the
		// pushes it had scheduled with no batch to count them into.
		//
		// Scheduling, until every push is: the batch's pushes count themselves
		// in, but nothing ends it yet. A push still running from before, for
		// one of these products, could finish while the rest were being
		// marked - and finding nothing waiting to run and every other product
		// saying queued, it closed the batch then and there, with all of them
		// as pushes that did not finish.
		self::update_export_state( array(
			'status'       => 'scheduling',
			'total_queued' => $queued,
			'total_pushed' => 0,
			'total_errors' => 0,
			'started_at'   => current_time( 'mysql' ),
			// Which products are this batch's, so its figures count only them,
			// and its end is judged from what they say.
			'product_ids'  => $batch,
		) );

		// What waits now is this batch: what waited before is cancelled above.
		update_option( self::BULK_WAITING_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'ids' => $batch ), false );

		// Say so on each product, as a push from its own screen does. A bulk
		// push wrote nothing here, so a product whose push never ran, or never
		// finished, looked exactly like one nobody had pushed - and the end of
		// the batch had no way to tell which ones those were.
		//
		// All of them before any push is scheduled: so that this cannot land on
		// top of a push that has already begun, and so that a request killed
		// while scheduling leaves the products it never scheduled saying
		// queued - a push that never ran, which the end of the batch reports
		// as one - rather than what they said before, which reads as pushed.
		//
		// Straight to the database: a full product save for each one would run
		// every other plugin's save hooks once per product, before a single
		// push has started.
		//
		// Except one whose earlier push did not finish: see push_product().
		foreach ( $batch as $product_id ) {
			if ( 'failed' === (string) get_post_meta( $product_id, '_ebay_export_status', true ) ) {
				continue;
			}
			update_post_meta( $product_id, '_ebay_export_status', 'queued' );
			update_post_meta( $product_id, '_ebay_export_error', '' );
		}

		foreach ( $batch as $index => $product_id ) {
			// Stagger jobs by 3 seconds each to avoid rate limiting.
			as_schedule_single_action(
				time() + 3 * $index,
				self::PUSH_ACTION,
				array( 'product_id' => $product_id ),
				'tcgiant_sync_group'
			);
		}

		TCGiant_Sync_Logger::log( sprintf( 'Export: Queued %d products for bulk push to eBay.', $queued ) );

		// Every push is scheduled: the batch can end now. Read afresh, for what
		// its pushes have counted while this request was busy - and asked at
		// once, because they may all have run already. Unless another bulk
		// push has begun meanwhile: the state is that one's, still scheduling.
		self::forget_cached_export_state();
		$state = self::get_export_state();
		if ( 'scheduling' === ( $state['status'] ?? '' ) && ( $state['product_ids'] ?? null ) === $batch ) {
			self::update_export_state( array( 'status' => 'queued' ) );
			$this->check_export_completion( 0 );
		}

		return $queued;
	}

	/**
	 * Products the last bulk_push_products() call left unqueued for the day's cap.
	 *
	 * @return int
	 */
	public function last_bulk_refused() {
		return $this->last_bulk_refused;
	}

	/**
	 * Pushes still waiting from the last bulk push that the last
	 * bulk_push_products() call replaced.
	 *
	 * @return int
	 */
	public function last_bulk_replaced() {
		return $this->last_bulk_replaced;
	}

	/**
	 * Note products a bulk push counted today and queued, for a later bulk
	 * push that cancels them to give back. The products list's and the coin
	 * update's come through bulk_push_products(); the Listings screen's job
	 * queues one at a time and notes each.
	 *
	 * @param int[] $ids Products queued.
	 */
	public static function note_bulk_queued( array $ids ) {
		$record = get_option( self::BULK_WAITING_OPTION, array() );
		$today  = gmdate( 'Y-m-d' );
		$held   = is_array( $record ) && $today === (string) ( $record['day'] ?? '' ) ? array_map( 'intval', (array) ( $record['ids'] ?? array() ) ) : array();
		update_option( self::BULK_WAITING_OPTION, array(
			'day' => $today,
			'ids' => array_values( array_unique( array_merge( $held, array_map( 'intval', $ids ) ) ) ),
		), false );
	}

	/**
	 * Pushes a bulk push counted today that are still waiting to run: the
	 * ones a new bulk push cancels.
	 *
	 * @return int
	 */
	private static function waiting_bulk_pushes() {
		$record = get_option( self::BULK_WAITING_OPTION, array() );
		$ids    = is_array( $record ) && gmdate( 'Y-m-d' ) === (string) ( $record['day'] ?? '' ) ? array_flip( array_map( 'intval', (array) ( $record['ids'] ?? array() ) ) ) : array();
		if ( ! $ids || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		// Each product once: one counted, pushed again from its own screen
		// while it waits, is still one.
		$waiting = array();
		foreach ( (array) as_get_scheduled_actions( array(
			'hook'     => self::PUSH_ACTION,
			'group'    => 'tcgiant_sync_group',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		) ) as $action ) {
			$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? $action->get_args() : array();
			$id   = (int) ( $args['product_id'] ?? 0 );
			if ( isset( $ids[ $id ] ) ) {
				$waiting[ $id ] = true;
			}
		}
		return count( $waiting );
	}

	/**
	 * A push has run: its product no longer waits, so a push of it queued
	 * later from its own screen, which the cap never counted, is never given
	 * back as if it were.
	 *
	 * @param int $product_id Product.
	 */
	private static function forget_bulk_queued( $product_id ) {
		$record = get_option( self::BULK_WAITING_OPTION, array() );
		if ( ! is_array( $record ) || empty( $record['ids'] ) ) {
			return;
		}
		$ids  = array_map( 'intval', (array) $record['ids'] );
		$left = array_values( array_diff( $ids, array( (int) $product_id ) ) );
		if ( count( $left ) !== count( $ids ) ) {
			update_option( self::BULK_WAITING_OPTION, array( 'day' => (string) ( $record['day'] ?? '' ), 'ids' => $left ), false );
		}
	}

	/**
	 * The most products bulk pushes may queue a day on this site.
	 *
	 * @return int
	 */
	public static function bulk_push_cap() {
		$cap = get_option( self::BULK_PUSH_CAP_OPTION, self::BULK_PUSH_CAP_DEFAULT );
		if ( ! is_numeric( $cap ) ) {
			return self::BULK_PUSH_CAP_DEFAULT;
		}
		return (int) max( self::BULK_PUSH_CAP_MIN, min( self::BULK_PUSH_CAP_MAX, (int) $cap ) );
	}

	/**
	 * Set the cap, held between 50 and 20,000.
	 *
	 * @param int $cap Products a day.
	 */
	public static function set_bulk_push_cap( $cap ) {
		update_option( self::BULK_PUSH_CAP_OPTION, (int) max( self::BULK_PUSH_CAP_MIN, min( self::BULK_PUSH_CAP_MAX, (int) $cap ) ), false );
	}

	/**
	 * Products bulk pushes have queued today.
	 *
	 * Today as the eBay call counter has it (TCGiant_Sync_API::
	 * get_daily_call_count()): the UTC date, so the cap and the calls it
	 * protects turn over together.
	 *
	 * @return int
	 */
	public static function bulk_pushed_today() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::BULK_PUSHED_OPTION, 'options' );
		}
		$day = get_option( self::BULK_PUSHED_OPTION, array() );
		if ( ! is_array( $day ) || gmdate( 'Y-m-d' ) !== ( $day['day'] ?? '' ) ) {
			return 0;
		}
		return max( 0, (int) ( $day['count'] ?? 0 ) );
	}

	/**
	 * "Bulk pushes today: 120 of 2,000", for wherever a bulk push starts.
	 *
	 * @return string
	 */
	public static function bulk_push_line() {
		return sprintf(
			/* translators: 1: products bulk-pushed today, 2: the daily limit */
			__( 'Bulk pushes today: %1$s of %2$s', 'tcgiant-sync' ),
			number_format_i18n( self::bulk_pushed_today() ),
			number_format_i18n( self::bulk_push_cap() )
		);
	}

	/**
	 * Take up to $wanted of today's bulk pushes, and say how many were had.
	 *
	 * Counts products accepted into the queue, whatever then becomes of them.
	 * A push from a product's own screen never comes here.
	 *
	 * @param int $wanted     Products to queue.
	 * @param int $given_back Pushes counted today that taking this replaces
	 *                        (cancelled once it is taken), given back first.
	 *                        Nothing is given back when nothing may be taken.
	 * @return int How many may be queued.
	 */
	public static function take_bulk_push_allowance( $wanted, $given_back = 0 ) {
		$wanted = max( 0, (int) $wanted );
		$today  = max( 0, self::bulk_pushed_today() - max( 0, (int) $given_back ) );
		$take   = min( $wanted, max( 0, self::bulk_push_cap() - $today ) );

		if ( $take > 0 ) {
			update_option( self::BULK_PUSHED_OPTION, array(
				'day'   => gmdate( 'Y-m-d' ),
				'count' => $today + $take,
			), false );
		}

		return $take;
	}

	// -------------------------------------------------------------------------
	// Action Scheduler Callback
	// -------------------------------------------------------------------------

	/**
	 * Process a single product push (called by Action Scheduler).
	 *
	 * @param int $product_id WooCommerce Product ID.
	 */
	public function process_single_push( $product_id ) {
		$product_id = (int) $product_id;
		self::forget_bulk_queued( $product_id );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			self::count_outcome( $product_id, 'total_errors' );
			TCGiant_Sync_Logger::error( sprintf( 'Export: Product ID %d not found during async push.', $product_id ) );
			$this->check_export_completion( $product_id );
			return;
		}

		$title = $product->get_name();

		// What an earlier push that did not finish said, when this one follows
		// it: its own words, without what they say of a push since that made
		// nothing. It stands unless this one finishes: see push_product().
		$unfinished = null;
		if ( 'failed' === (string) $product->get_meta( '_ebay_export_status' ) ) {
			$unfinished = (string) $product->get_meta( '_ebay_export_error' );
			$unfinished = strstr( $unfinished, self::REFUSED_AFTER_UNFINISHED, true ) ?: $unfinished;
		}

		// Watched, so that a push PHP kills says so - which product, and
		// whether it died waiting for eBay or saving what eBay said. The item
		// number is only written to the product at the save below; until then
		// it is in memory, and a request killed in between left a live eBay
		// listing that nothing in WooCommerce pointed to, and no line at all.
		self::$in_flight = array(
			'product_id' => $product_id,
			'title'      => (string) $title,
			'linked'     => (string) $product->get_meta( '_ebay_item_id' ),
			// A linked listing that has ended is not revised: eBay refuses, and
			// the push makes a new listing in its place.
			'ended'      => self::linked_listing_ended( $product_id ),
			'created'    => '',
			'unfinished' => $unfinished,
		);
		TCGiant_Sync_Runtime::watch( self::PUSH_JOB, array( __CLASS__, 'on_push_death' ) );
		TCGiant_Sync_Runtime::context( sprintf( 'WC #%d "%s"', $product_id, $title ) );

		try {
			// On the product, in the database, before eBay is asked. Written
			// straight to the table rather than with the product's own save,
			// which would hold it in memory with everything else - a request
			// killed while waiting for eBay must still leave this behind, so the
			// product shows a push that began and never came back.
			update_post_meta( $product_id, '_ebay_export_status', 'pushing' );
			update_post_meta( $product_id, '_ebay_export_pushing_since', time() );

			// The product read its meta before those two writes. Read it again,
			// so its own save below updates those rows instead of adding a
			// second row of each beside them.
			$product->read_meta_data( true );

			TCGiant_Sync_Runtime::stage( 'sending to eBay' );
			$result = $this->do_push( $product );

			if ( is_wp_error( $result ) ) {
				$error_msg = self::explain_ebay_error( $result->get_error_message() );
				$earlier   = self::$in_flight['unfinished'];

				// Refused: this push made nothing, but the one before it may
				// have. Marked error, the product read as one eBay had refused:
				// Clear was offered, Link left it as it was, and it was trashed
				// as an imported product when the listing that push made sold out.
				// It says what that one said, then why this one was refused: with
				// that one's words alone, the product looked just as it had before
				// it was pushed again, and only the log said why.
				$product->update_meta_data( '_ebay_export_status', null === $earlier ? 'error' : 'failed' );
				$product->update_meta_data( '_ebay_export_error', null === $earlier ? $error_msg : $earlier . self::REFUSED_AFTER_UNFINISHED . $error_msg );
				$product->delete_meta_data( '_ebay_export_pushing_since' );
				TCGiant_Sync_Runtime::guard_loops();
				try {
					$product->save();
				} finally {
					TCGiant_Sync_Runtime::unguard_loops();
				}

				// Still a push that did not finish, which the end of the batch
				// names as one: counted as an error too, it would be counted twice.
				if ( null === $earlier ) {
					self::count_outcome( $product_id, 'total_errors' );
				}
				TCGiant_Sync_Logger::error( sprintf(
					'Export failed for "%s" (WC #%d): %s%s',
					$title,
					$product_id,
					$error_msg,
					null === $earlier ? '' : ' Its earlier push did not finish, and it still says so: use Link if that push made a listing.'
				) );
			} else {
				$item_id = $result['item_id'];
				$action  = $result['action']; // 'created', 'updated' or 'relisted'

				// eBay has made a listing that, until the save below, only this
				// request knows the number of. If it dies now, the record of it
				// gives the number, so the shop can link it instead of pushing
				// again and listing the product twice.
				if ( 'updated' !== $action ) {
					self::$in_flight['created'] = (string) $item_id;
				}
				TCGiant_Sync_Runtime::stage( 'saving the link' );

				$product->update_meta_data( '_ebay_item_id', $item_id );
				$product->update_meta_data( '_ebay_export_status', 'pushed' );
				$product->update_meta_data( '_ebay_export_error', '' );
				$product->update_meta_data( '_ebay_export_last_pushed', current_time( 'mysql' ) );

				// Record which build sent this. Without it there is no way to tell a
				// listing carrying the right item specifics from one sent before a fix
				// that added them — the listing on eBay looks the same from here.
				$product->update_meta_data( '_ebay_export_pushed_version', TCGIANT_SYNC_VERSION );

				// And the description eBay now has: which template and version,
				// and the hashes 3.28.1's re-send compares. Only here, once eBay
				// has accepted it - never in build_item_xml(), which Verify and
				// the preview call too.
				self::record_description_sent( $product, $result['desc'] ?? null, (string) $item_id );

				// A newly created listing is live, so clear any stale "Ended"
				// state left over from the listing it replaced — otherwise the
				// product keeps showing as ended and the ended-listing cron
				// would mark it so again.
				if ( 'updated' !== $action ) {
					$product->update_meta_data( '_ebay_listing_status', 'Active' );
					$product->delete_meta_data( '_ebay_end_time' );

					// The new listing is on the shop's own site - build_item_xml()
					// sends <Site> from the same setting - whatever site the one it
					// replaced was on. Left as the old one's, the next push to find
					// this listing ended would be refused for a site it is not on,
					// for want of a sync that saves it again.
					$product->update_meta_data( '_ebay_site', TCGiant_Sync_API::instance()->get_marketplace_config()['site'] );

					// What was settled about the listing this replaces is spent.
					foreach ( TCGiant_Sync_Inventory::SETTLE_KEYS as $settle_key ) {
						$product->delete_meta_data( $settle_key );
					}
					delete_transient( 'tcgiant_restocked_after_sale' );

					// Record what was created. The revise path picks its eBay call from
					// this, so a product relisted as an auction that still read as
					// fixed-price would have been revised through the wrong one.
					if ( ! empty( $result['listing_type'] ) ) {
						$product->update_meta_data( '_ebay_listing_type', $result['listing_type'] );
					}
				}

				if ( ! empty( $result['previous_item_id'] ) ) {
					$product->update_meta_data( '_ebay_previous_item_id', $result['previous_item_id'] );
					TCGiant_Sync_Logger::log( sprintf(
						'Relisted "%s" (WC #%d): ended listing %s replaced by new listing %s.',
						$title,
						$product_id,
						$result['previous_item_id'],
						$item_id
					), 'success' );
				}

				$product->delete_meta_data( '_ebay_export_pushing_since' );
				TCGiant_Sync_Runtime::guard_loops();
				try {
					$product->save();
				} finally {
					TCGiant_Sync_Runtime::unguard_loops();
				}

				// Put it on the Listings screen.
				//
				// Nothing here ever wrote to that table. Only the importer did, so a
				// shop that lists by pushing from WooCommerce - never importing -
				// had an empty Listings screen no matter how much it had listed,
				// which put End Listing, Relist and bulk Push out of reach for
				// exactly the products the plugin had created.
				//
				// A no-op when the table is missing, so it cannot fail a push.
				$row = array(
					'product_id'     => $product_id,
					'ebay_item_id'   => $item_id,
					'listing_type'   => $product->get_meta( '_ebay_listing_type' ) ?: 'FixedPriceItem',
					'listing_status' => 'Active',
					'ebay_url'       => 'https://www.ebay.com/itm/' . $item_id,
					'ebay_title'     => $title,
					// A push creates the listing, so it started now. Without this a
					// freshly listed item would sort as though it had no start date
					// until the hourly refresh next looked at it.
					'ebay_start_time' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
					'last_synced'    => current_time( 'mysql' ),
					'last_pushed'    => current_time( 'mysql' ),
				);

				// Price and quantity only where this product carries the real
				// figures. A variable product's live on its variations, so reading
				// them here would record 0.00 and 0 and the Listings screen would
				// show every variable listing as free and sold out. A column left
				// out keeps whatever the last import knew, which is the honest
				// answer rather than a confident wrong one.
				if ( ! $product->is_type( 'variable' ) ) {
					$price = $product->get_regular_price();
					if ( is_numeric( $price ) ) {
						$row['ebay_price'] = (float) $price;
					}

					$stock = $product->get_stock_quantity();
					if ( null !== $stock && '' !== $stock ) {
						$row['ebay_quantity'] = max( 0, (int) $stock );
					}
				}

				// An auction is one item however many are in stock, and that is what
				// was sent.
				if ( 'Chinese' === $row['listing_type'] ) {
					$row['ebay_quantity'] = 1;
				}

				TCGiant_Sync_DB::upsert( $row );

				self::count_outcome( $product_id, 'total_pushed', array( 'last_item_title' => $title ) );

				TCGiant_Sync_Logger::log(
					sprintf(
						'Export %s: "%s" (WC #%d) → eBay Item ID %s.',
						$action, $title, $product_id, $item_id
					),
					'success'
				);
			}
		} catch ( Exception $e ) {
			// One that threw after eBay had made a listing did not finish, and
			// the end of the batch names it as one: counted as an error too,
			// it would be counted twice.
			if ( ! self::record_push_exception( sprintf( 'Export exception for "%s" (WC #%d): %s', $title, $product_id, $e->getMessage() ) ) ) {
				self::count_outcome( $product_id, 'total_errors' );
			}
		} catch ( Error $e ) {
			if ( ! self::record_push_exception( sprintf( 'Export fatal error for "%s" (WC #%d): %s', $title, $product_id, $e->getMessage() ) ) ) {
				self::count_outcome( $product_id, 'total_errors' );
			}
		} finally {
			TCGiant_Sync_Runtime::unwatch();
			self::$in_flight = null;
		}

		$this->check_export_completion( $product_id );
	}

	// -------------------------------------------------------------------------
	// A Push That Did Not Finish
	// -------------------------------------------------------------------------

	/**
	 * Count one push's outcome in the export figures.
	 *
	 * While a bulk push is running its figures are its own. A product pushed
	 * from its own screen meanwhile used to be counted into them, so a batch of
	 * three in which one push died could still add up to three and call itself
	 * complete. Outside a bulk push every push counts, as it always has: the
	 * export screen's total is made of them.
	 *
	 * Counted from the state as the database has it, not this request's
	 * copy. The count writes the whole state back, and a queue runner that
	 * began while a bulk push was still being scheduled holds it as
	 * "scheduling": written back, the batch said so again, and nothing ended
	 * it until it was fifteen minutes old.
	 *
	 * @param int    $product_id The product pushed.
	 * @param string $field      total_pushed or total_errors.
	 * @param array  $also       Other state to write with it.
	 */
	private static function count_outcome( $product_id, $field, array $also = array() ) {
		self::forget_cached_export_state();
		$state = self::get_export_state();
		$batch = isset( $state['product_ids'] ) && is_array( $state['product_ids'] ) ? array_map( 'intval', $state['product_ids'] ) : null;

		if ( null !== $batch && in_array( $state['status'] ?? '', array( 'scheduling', 'queued', 'pushing' ), true ) && ! in_array( (int) $product_id, $batch, true ) ) {
			return;
		}

		self::update_export_state( array_merge( $also, array( $field => (int) ( $state[ $field ] ?? 0 ) + 1 ) ) );
	}

	/**
	 * A push that threw: logged, and said on the product.
	 *
	 * The product's status used to be left as it was, so it went on saying
	 * "queued" for a push whose error was right there in the log - and from
	 * this version it would go on saying "pushing", and then that the push
	 * never finished.
	 *
	 * One that threw after eBay had made a listing the product does not hold
	 * is a push that did not finish, as one the host killed is, and the
	 * product is marked failed, not error. Marked error, it read as a push
	 * eBay had refused - Unlink dropped it, Link left it, and Clear was
	 * offered on it - so doing as it said, Unlink then Link, or Link, left a
	 * product whose push had made the listing looking imported, and "Remove
	 * sold products" trashed it when that listing sold out.
	 *
	 * One that threw with nothing made, after an earlier push that did not
	 * finish, leaves the product saying that one did not, in its words, and
	 * then what stopped this one.
	 *
	 * @param string $line What happened, as it is logged.
	 * @return bool Whether the product is left saying a push did not finish:
	 *              eBay had made a listing the product does not hold, or an
	 *              earlier push did not finish.
	 */
	private static function record_push_exception( $line ) {
		$push       = self::$in_flight;
		$product_id = is_array( $push ) ? (int) $push['product_id'] : 0;

		// eBay may already have made the listing, and the number is known only
		// here, in this request. Still linked to the listing it replaced, the
		// product has no Link to use until it is unlinked from that one.
		$made = $product_id && '' !== $push['created'] && (string) get_post_meta( $product_id, '_ebay_item_id', true ) !== $push['created'];
		if ( $made ) {
			$line .= '' !== $push['linked']
				? sprintf( ' eBay had already created listing %1$s for it in place of ended listing %2$s: Unlink it from listing %2$s, then use Link with item number %1$s; pushing it again would list it twice.', $push['created'], $push['linked'] )
				: sprintf( ' eBay had already created listing %s for it: use Link on the product with that item number; pushing it again would list it twice.', $push['created'] );
		}

		TCGiant_Sync_Logger::error( $line );

		// One that made nothing, after an earlier push that did not finish,
		// leaves the product saying that one did not: see push_product().
		$earlier = ( ! $made && $product_id && isset( $push['unfinished'] ) ) ? (string) $push['unfinished'] : null;

		if ( $product_id ) {
			update_post_meta( $product_id, '_ebay_export_status', ( $made || null !== $earlier ) ? 'failed' : 'error' );
			update_post_meta( $product_id, '_ebay_export_error', null !== $earlier ? $earlier . self::REFUSED_AFTER_UNFINISHED . $line : $line );
			delete_post_meta( $product_id, '_ebay_export_pushing_since' );
		}

		return $made || null !== $earlier;
	}

	/**
	 * PHP has killed a push.
	 *
	 * Runs on shutdown, after the record of the death is written, so it must
	 * allocate little. What it says is built from what the push already held,
	 * and goes straight to the log file and the product's meta.
	 *
	 * @param string $message What the record of the death said.
	 */
	public static function on_push_death( $message ) {
		$push            = self::$in_flight;
		self::$in_flight = null;

		if ( ! is_array( $push ) ) {
			return;
		}

		self::report_unfinished_push( $push['product_id'], $push['title'], $push['linked'], $push['ended'], $push['created'], 'your server stopped it', true );
	}

	/**
	 * Action Scheduler found a push still running five minutes on, and has
	 * marked it failed.
	 *
	 * Time is all its queue cleaner goes by: an action still marked as running
	 * that long after it began. Nearly always the request running it was
	 * killed outright, but a push that was only slow looks just the same, so
	 * this says what was seen. A death PHP itself saw, reported from the
	 * dying request, is the one that can say the server stopped it.
	 *
	 * @param int $action_id The action.
	 * @param int $timeout   Seconds it waited.
	 */
	public function on_push_action_failed( $action_id, $timeout = 0 ) {
		$timeout = (int) $timeout;

		if ( $timeout >= 60 ) {
			$minutes = (int) round( $timeout / 60 );
			$waited  = $minutes . ( 1 === $minutes ? ' minute' : ' minutes' );
		} else {
			$waited = $timeout > 0 ? $timeout . ( 1 === $timeout ? ' second' : ' seconds' ) : '';
		}

		$why = '' !== $waited
			? sprintf( 'Action Scheduler found it still running %s on and gave up on it, so your server most likely stopped it', $waited )
			: 'Action Scheduler found it still running and gave up on it, so your server most likely stopped it';

		$this->push_action_ended( $action_id, $why, false );
	}

	/**
	 * Action Scheduler saw a PHP fatal error end the request running a push.
	 * Runs on shutdown, in the dying request.
	 *
	 * @param int   $action_id The action.
	 * @param array $error     error_get_last().
	 */
	public function on_push_action_shutdown( $action_id, $error = array() ) {
		$this->push_action_ended( $action_id, 'your server stopped it', true );
	}

	/**
	 * A push threw past everything that catches it inside the push.
	 *
	 * @param int            $action_id The action.
	 * @param Throwable|null $exception What was thrown.
	 */
	public function on_push_action_threw( $action_id, $exception = null ) {
		$why = $exception instanceof Throwable
			? sprintf( 'it stopped with an error (%s)', $exception->getMessage() )
			: 'it stopped with an error';

		$this->push_action_ended( $action_id, $why, false );
	}

	/**
	 * One of Action Scheduler's actions ended without finishing. If it was a
	 * push, say so on the product and in the log, and see whether the batch
	 * it belonged to was waiting on anything else.
	 *
	 * @param int    $action_id The action.
	 * @param string $why       How it ended, for the log line.
	 * @param bool   $dying     Whether this request is on its way out.
	 */
	private function push_action_ended( $action_id, $why, $dying ) {
		$product_id = self::push_action_product( $action_id );
		if ( ! $product_id ) {
			return;
		}

		$push = self::$in_flight;
		$ours = is_array( $push ) && (int) $push['product_id'] === $product_id;

		// This request's own push, dying now, is left to on_push_death(): the
		// record of the death is still to be written, and saying what to do
		// about it belongs after it.
		if ( ! ( $dying && $ours ) ) {
			self::report_unfinished_push(
				$product_id,
				$ours ? $push['title'] : (string) get_post_field( 'post_title', $product_id ),
				$ours ? $push['linked'] : (string) get_post_meta( $product_id, '_ebay_item_id', true ),
				// The push died before it wrote anything back, so what the product
				// says of its listing is what the push began with.
				$ours ? $push['ended'] : self::linked_listing_ended( $product_id ),
				$ours ? $push['created'] : '',
				$why,
				$dying
			);
		}

		if ( $dying ) {
			// Last of all on the way out, after the record of the death: this
			// reads and writes the export state, which takes memory a request
			// that has just run out of it may not have.
			register_shutdown_function( array( $this, 'complete_export_after_death' ), $product_id );
			return;
		}

		$this->check_export_completion( $product_id );
	}

	/**
	 * The batch a dead push belonged to may have been waiting on nothing
	 * else, and no other push is left to say it has finished. Runs on
	 * shutdown, last.
	 *
	 * @param int $product_id The product whose push died.
	 */
	public function complete_export_after_death( $product_id ) {
		try {
			$this->check_export_completion( (int) $product_id );
		} catch ( Throwable $e ) {
			// The push's own line is written; this was only the summary.
			unset( $e );
		}
	}

	/**
	 * The product an Action Scheduler action pushes, or 0 if it is not a push.
	 *
	 * @param int $action_id The action.
	 * @return int
	 */
	private static function push_action_product( $action_id ) {
		// is_callable() rather than method_exists(): the same answer for
		// ActionScheduler::store(), which is public and static, and one PHPStan
		// can follow without stubs for Action Scheduler.
		if ( ! class_exists( 'ActionScheduler' ) || ! is_callable( array( 'ActionScheduler', 'store' ) ) ) {
			return 0;
		}

		try {
			$action = ActionScheduler::store()->fetch_action( $action_id );
		} catch ( Exception $e ) {
			return 0;
		}

		if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) || self::PUSH_ACTION !== $action->get_hook() ) {
			return 0;
		}

		$args = $action->get_args();
		if ( ! is_array( $args ) || ! $args ) {
			return 0;
		}

		return (int) ( isset( $args['product_id'] ) ? $args['product_id'] : reset( $args ) );
	}

	/**
	 * Say in the log and on the product that a push did not finish.
	 *
	 * Once only. Action Scheduler and the record of the death can each report
	 * the same push, and a push that has finished since - or never began - is
	 * left alone.
	 *
	 * @param int    $product_id The product.
	 * @param string $title      Its name.
	 * @param string $linked     The eBay listing it was linked to, or ''.
	 * @param bool   $ended      Whether that listing had ended.
	 * @param string $created    The listing eBay created for it, if known.
	 * @param string $why        How it ended.
	 * @param bool   $dying      Whether this request is on its way out.
	 * @return bool Whether anything was said.
	 */
	private static function report_unfinished_push( $product_id, $title, $linked, $ended, $created, $why, $dying ) {
		$product_id = (int) $product_id;
		$status     = (string) get_post_meta( $product_id, '_ebay_export_status', true );

		if ( 'pushing' !== $status && 'queued' !== $status ) {
			return false;
		}

		$message = self::unfinished_push_message( $product_id, $title, $linked, $ended, $created, $why );

		// The line first: an append to the file, and what the shop reads.
		if ( $dying ) {
			TCGiant_Sync_Logger::write_now( $message, 'error' );
		} else {
			TCGiant_Sync_Logger::error( $message );
		}

		update_post_meta( $product_id, '_ebay_export_status', 'failed' );
		update_post_meta( $product_id, '_ebay_export_error', $message );

		return true;
	}

	/**
	 * What to tell the shop about a push that did not finish.
	 *
	 * The danger is a second listing. eBay may well have created the first
	 * before the request was stopped, and the product, holding no item
	 * number, looks as if it was never pushed - so the obvious thing to do,
	 * pushing it again, lists it twice. Link is the way out.
	 *
	 * A linked product has no Link to use: the product screen offers it only
	 * to a product with no listing. Its push was revising that listing - or,
	 * if the listing had ended, making a new one in its place. The old one
	 * then looks exactly as it should, and checking it before pushing again
	 * is how the second new listing got made. Unlink, then Link, is the way
	 * out there.
	 *
	 * @param int    $product_id The product.
	 * @param string $title      Its name.
	 * @param string $linked     The eBay listing it was linked to, or ''.
	 * @param bool   $ended      Whether that listing had ended.
	 * @param string $created    The listing eBay created for it, if known.
	 * @param string $why        How it ended.
	 * @return string
	 */
	private static function unfinished_push_message( $product_id, $title, $linked, $ended, $created, $why ) {
		$head = sprintf( 'The push of "%s" (WC #%d) did not finish - %s', $title, (int) $product_id, $why );

		// Linked, and given a new listing: the old one had ended, and this was
		// its replacement.
		if ( '' !== (string) $created && '' !== (string) $linked ) {
			return $head . sprintf( ' after eBay created listing %1$s in place of ended listing %2$s. Unlink it from listing %2$s, then use Link with item number %1$s; pushing it again would list it twice.', $created, $linked );
		}

		if ( '' !== (string) $created ) {
			return $head . sprintf( ' after eBay created listing %s. Use Link on the product with that item number; pushing it again would list it twice.', $created );
		}

		if ( '' !== (string) $linked ) {
			return $head . ( $ended
				? sprintf( '. It is linked to eBay listing %s, which had ended, so this push was making a new one in its place: look on eBay for it, and if it is there, Unlink this product and Link it to the new item number - pushing it again would list it twice.', $linked )
				: sprintf( '. It is linked to eBay listing %s. If that listing had ended, this push was making a new one in its place: look on eBay for it, and if it is there, Unlink this product and Link it to the new item number - pushing it again would list it twice.', $linked ) );
		}

		return $head . '. If the listing is on eBay, use Link on the product with its item number; pushing it again would list it twice.';
	}

	/**
	 * Whether the listing a product is linked to is over, as far as the
	 * product knows: a sync marked it ended, or its end time has passed. The
	 * same judgement as TCGiant_Sync_Admin::listing_has_ended(), which the
	 * product screens make - here because the push runs where the admin
	 * class is not loaded.
	 *
	 * @param int $product_id The product.
	 * @return bool
	 */
	public static function linked_listing_ended( $product_id ) {
		$product_id = (int) $product_id;

		if ( '' === (string) get_post_meta( $product_id, '_ebay_item_id', true ) ) {
			return false;
		}

		if ( 'Ended' === (string) get_post_meta( $product_id, '_ebay_listing_status', true ) ) {
			return true;
		}

		$end_time = (string) get_post_meta( $product_id, '_ebay_end_time', true );
		$ends_at  = '' !== $end_time ? strtotime( $end_time ) : false;

		return $ends_at && $ends_at < time();
	}

	/**
	 * Where a product's last push stands, for the product screens.
	 *
	 * @param int      $product_id The product.
	 * @param int|null $now        Unix time, for the harness; now if null.
	 * @return string 'pushing', 'stale' (said it was pushing for too long to
	 *                believe), 'failed' (did not finish), or ''.
	 */
	public static function push_progress( $product_id, $now = null ) {
		$status = (string) get_post_meta( $product_id, '_ebay_export_status', true );

		if ( 'failed' === $status ) {
			return 'failed';
		}

		if ( 'pushing' !== $status ) {
			return '';
		}

		$since = (int) get_post_meta( $product_id, '_ebay_export_pushing_since', true );
		$now   = null === $now ? time() : (int) $now;

		return ( $since > 0 && $now - $since > self::PUSH_STALE_AFTER ) ? 'stale' : 'pushing';
	}

	// -------------------------------------------------------------------------
	// Core Push Logic
	// -------------------------------------------------------------------------

	/**
	 * Execute the push for a single product — creates or updates the eBay listing.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return array|WP_Error On success, array with 'item_id' and 'action' keys.
	 */
	private function do_push( WC_Product $product ) {
		// A new push: its photos are decided afresh (get_product_image_urls()).
		$this->pictures_for = array();
		$this->grade_said   = array();

		$settings = $this->get_export_settings( $product->get_id() );

		// Validate required settings before hitting the API.
		$validation = $this->validate_export_settings( $settings );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Refuse to list a stock-managed product with nothing available.
		// build_item_xml() floors the quantity at 1 so that Verify still
		// produces valid XML, which meant pushing an out-of-stock product
		// created a live eBay listing offering one unit that cannot be
		// fulfilled — and, with auto-end-on-zero-stock enabled, the stock
		// listener then immediately ended the listing it had just created.
		if ( ! $product->is_type( 'variable' ) && $product->managing_stock() && (int) $product->get_stock_quantity() < 1 ) {
			return new WP_Error(
				'out_of_stock',
				__( 'Cannot list on eBay: this product has no stock available. Add stock, or turn off stock management for it, then push again.', 'tcgiant-sync' )
			);
		}

		// Fail locally with the specific missing aspects rather than sending a
		// request eBay will reject with a generic error.
		$missing_aspects = $this->find_missing_required_aspects( $product, $settings );
		if ( ! empty( $missing_aspects ) ) {
			return new WP_Error(
				'missing_required_aspects',
				sprintf(
					/* translators: %s: comma-separated list of eBay aspect names */
					__( 'eBay requires these item specifics for the selected category and they are empty: %s. Add them as product attributes (Product data → Attributes), then push again.', 'tcgiant-sync' ),
					implode( ', ', $missing_aspects )
				)
			);
		}

		// A product with a listing has its item built for the revise below,
		// which keeps the listing's photos when one of them would be left out.
		$ebay_item_id = $product->get_meta( '_ebay_item_id' );
		$item_xml     = $this->build_item_xml( $product, $settings, ! empty( $ebay_item_id ) );
		$held         = $this->pictures_held;

		// eBay refuses a description over 500,000 characters, tags included, and
		// a refusal is a call spent. A templated or eBay-only description is
		// measured; the plain one goes to eBay as 3.27.7 sent it.
		$too_long = $this->description_too_long();
		if ( is_wp_error( $too_long ) ) {
			return $too_long;
		}

		$api       = TCGiant_Sync_API::instance();

		// Refuse to revise a listing that more than one product claims.
		//
		// Duplicating a product used to copy _ebay_item_id, so the copy pointed
		// at the original's live listing. Revising from the copy would overwrite
		// a real listing with the copy's title, price and images. Products
		// duplicated before that was fixed still carry the stale link, so this
		// guard stays regardless.
		if ( ! empty( $ebay_item_id ) ) {
			$shared_with = TCGiant_Sync_Listing_Link::find_products_sharing_item_id( $product->get_id(), $ebay_item_id );
			if ( ! empty( $shared_with ) ) {
				$message = sprintf(
					/* translators: 1: eBay Item ID, 2: comma-separated product IDs */
					__( 'Refusing to update eBay listing %1$s: product(s) #%2$s are linked to the same listing. This usually means this product was duplicated from another one. Use "Unlink from eBay" on whichever product should not own the listing, then push again.', 'tcgiant-sync' ),
					$ebay_item_id,
					implode( ', ', $shared_with )
				);
				TCGiant_Sync_Logger::error( sprintf(
					'Blocked push for WC #%d — eBay Item %s is also claimed by WC #%s.',
					$product->get_id(), $ebay_item_id, implode( ', #', $shared_with )
				) );
				return new WP_Error( 'shared_item_id', $message );
			}
		}

		// eBay cannot change the format of a live listing, and says so in its own
		// words with nothing to suggest what to do instead. A seller moving ten
		// GTC listings to ten-day auctions pushed and got errors, ended the
		// listings by hand and pushed again, tried the bulk format action, and
		// finally republished them exactly as they had been.
		//
		// Refuse only where all three are known: a recorded format, a different
		// one asked for, and a listing recorded as Active. Anything ended, or
		// unrecorded, goes the ordinary way - the relist path below settles an
		// ended listing from eBay's own answer rather than from local state.
		// Variable products are exempt: they are always fixed-price whatever the
		// setting says, so a mismatch there means nothing.
		if ( ! empty( $ebay_item_id ) && ! $product->is_type( 'variable' ) ) {
			$live_format   = (string) $product->get_meta( '_ebay_listing_type' );
			// The format chosen FOR THIS PRODUCT, not the shop-wide default. A shop
			// whose default is Fixed Price may hold auctions imported from eBay and
			// push price changes to them all day; that must go on working. Only a
			// choice made against this product - in its eBay Listing tab, or by the
			// bulk format action - says the seller means to change the format.
			$wanted_format = (string) $product->get_meta( '_ebay_export_listing_type' );
			$is_active     = 'Active' === (string) $product->get_meta( '_ebay_listing_status' );

			if ( $is_active && '' !== $live_format && '' !== $wanted_format && $live_format !== $wanted_format ) {
				$labels = TCGiant_Sync_Catalog::LISTING_TYPES;

				return new WP_Error(
					'format_change_needs_new_listing',
					sprintf(
						/* translators: 1: current listing format, 2: requested format, 3: eBay Item ID */
						__( 'eBay cannot change a live listing from %1$s to %2$s - it needs a new listing. End listing %3$s first (TCGiant Sync, Listings, tick the products and choose End Listing), then push again: the new %2$s listing is created for you.', 'tcgiant-sync' ),
						$labels[ $live_format ] ?? $live_format,
						$labels[ $wanted_format ] ?? $wanted_format,
						$ebay_item_id
					)
				);
			}
		}

		$previous_item_id = '';
		$ended_on         = ''; // The ended listing's site, when eBay's answer said it below.
		$fixed_price      = $this->uses_fixed_price_calls( $product, $settings, $ebay_item_id );

		if ( ! empty( $ebay_item_id ) ) {
			// Existing listing — revise it.
			$full_xml = '<Item>' . "\n" . '<ItemID>' . esc_attr( $ebay_item_id ) . '</ItemID>' . "\n" . $item_xml . "\n" . '</Item>';
			$response = $fixed_price
				? $api->revise_fixed_price_item( $full_xml )
				: $api->revise_item( $full_xml );

			// The photos this revise was not sent, said once eBay has answered -
			// unless the listing has ended: the new listing made below says what
			// it goes without instead.
			if ( $held && ! self::is_ended_listing_error( $response ) ) {
				TCGiant_Sync_Logger::warning( self::photos_unchanged_warning( $product->get_id(), $held ) );
			}

			if ( ! is_wp_error( $response ) ) {
				// ReviseItem returns ItemID in the response.
				$returned_item_id = $response['ItemID'] ?? $ebay_item_id;
				return array( 'item_id' => $returned_item_id, 'action' => 'updated', 'desc' => $this->last_render );
			}

			// An ended listing cannot be revised, and its format cannot be
			// changed — eBay requires a brand new listing. Previously this was
			// a dead end: the product kept its old Item ID, so every push tried
			// to revise again and failed the same way, with no route back to
			// being listed.
			//
			// The decision is made from eBay's own response rather than local
			// status, so a listing that is actually still live can never be
			// duplicated by mistake.
			if ( ! self::is_ended_listing_error( $response ) ) {
				return $response;
			}

			// A relist in all but name: never a new listing for an ended one on
			// another eBay site, when the shop imports only its own
			// (TCGiant_Sync_Sites). It would go up on the shop's own site at the
			// copy's figures, in the copy's currency. As for Relist, no product
			// is spared for having been pushed before: a copy revised once by
			// Push carries the same marks as the shop's own. From the site
			// recorded on the product first, before eBay is asked anything.
			$ended_site = (string) $product->get_meta( '_ebay_site' );
			if ( TCGiant_Sync_Sites::leaves_out( $ended_site ) ) {
				return $this->other_site_relist_refusal( $ended_site );
			}

			// Before creating a new listing for an ended one, make sure the stock
			// it would be created with is real - see ended_listing_check().
			$ended_answer = null;
			$check        = $this->ended_listing_check( $product, (string) $ebay_item_id, null, $ended_answer );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			// Then from eBay's own answer, when the check read one: it covers a
			// product with no site recorded, or one recorded wrongly. After the
			// check, so the listing's own sales are still brought into the stock
			// from that answer, and recorded, so the next refusal comes first.
			//
			// The check asks nothing for a variable product, or for a listing
			// whose sales are already in the stock - the usual state of a copy
			// that ended before 3.27.5, since the full sync reads live listings
			// only and the settle never recorded a site. With no site recorded
			// either, eBay is asked here, once, as Relist always asks. Going
			// ahead without an answer would put the copy back up on the shop's
			// own site, at its figures, and mark the product pushed - the shop's
			// own from then on, off Stock Review for good.
			if ( ! is_array( $ended_answer ) && '' === $ended_site && TCGiant_Sync_Sites::primary_only() ) {
				$ended_detail = $api->get_item( (string) $ebay_item_id );
				if ( is_wp_error( $ended_detail ) || empty( $ended_detail['Item'] ) ) {
					return new WP_Error( 'relist_unchecked', sprintf(
						/* translators: 1: eBay item ID, 2: error */
						__( 'could not ask eBay which site listing %1$s is on (%2$s), so it was not listed again', 'tcgiant-sync' ),
						$ebay_item_id,
						is_wp_error( $ended_detail ) ? $ended_detail->get_error_message() : __( 'no answer', 'tcgiant-sync' )
					) );
				}
				$ended_answer = $ended_detail['Item'];
			}

			if ( is_array( $ended_answer ) ) {
				$listed_on = TCGiant_Sync_Sites::site_of( $ended_answer );
				if ( TCGiant_Sync_Sites::leaves_out( $listed_on ) ) {
					TCGiant_Sync_Sites::record_site( $product->get_id(), $listed_on );
					return $this->other_site_relist_refusal( $listed_on );
				}
				$ended_on = $listed_on;
			}

			// The stock may have come down to what is really left, and the item
			// was built from the figure before that. And an item built for the
			// revise without its photos is built again with them: a new listing
			// goes without only the ones that cannot be put on eBay, and those
			// it has no room for, said once - here, as the revise's build did not.
			if ( $check || $held ) {
				if ( $check ) {
					$product = wc_get_product( $product->get_id() );
				}
				$item_xml = $this->build_item_xml( $product, $settings );
				if ( $held ) {
					TCGiant_Sync_Logger::warning( self::left_out_warning( $product->get_id(), $held ) );
					$this->say_pictures_not_sent( $product->get_id() );
				}

				$too_long = $this->description_too_long();
				if ( is_wp_error( $too_long ) ) {
					return $too_long;
				}
			}

			TCGiant_Sync_Logger::log( sprintf(
				'eBay listing %s has ended and cannot be revised — creating a new listing for WC #%d instead.',
				$ebay_item_id,
				$product->get_id()
			) );

			$previous_item_id = $ebay_item_id;
			$ebay_item_id     = '';

			// Decide the call family again, now that there is no listing to read a
			// format from. It was chosen above from the listing that has just
			// ended - whose format is precisely what the seller is changing - so a
			// fixed-price GTC listing relisted as a ten-day auction was sent
			// through AddFixedPriceItem, and eBay refused it. Ending the listing by
			// hand first changed nothing: the product keeps its Item ID until a new
			// listing replaces it, and the Item ID is what the format was read from.
			$fixed_price = $this->uses_fixed_price_calls( $product, $settings, '' );
		}

		{
			// New listing — add it. UUID prevents duplicate creation on timeout/retry.
			$uuid = TCGiant_Sync_API::generate_ebay_uuid();
			$full_xml = '<Item>' . "\n" . '<UUID>' . $uuid . '</UUID>' . "\n" . $item_xml . "\n" . '</Item>';
			$response = $fixed_price
				? $api->add_fixed_price_item( $full_xml )
				: $api->add_item( $full_xml );

			// eBay listed nothing, so the product stays on its ended listing,
			// and keeps that listing's site from the answer read above, as
			// Relist keeps it. Not kept, every push after eBay refused one - a
			// category it no longer takes, say - asked eBay the same question
			// again. Only here: once a listing is made, the push records the new
			// listing's site on the product itself, and a write of its own first
			// would leave a second row beside that one.
			if ( is_wp_error( $response ) || empty( $response['ItemID'] ) ) {
				TCGiant_Sync_Sites::record_site( $product->get_id(), $ended_on );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( empty( $response['ItemID'] ) ) {
				return new WP_Error( 'no_item_id', __( 'eBay did not return an Item ID after AddItem. Listing may have been created — check your eBay account.', 'tcgiant-sync' ) );
			}

			return array(
				'item_id'          => $response['ItemID'],
				'action'           => $previous_item_id ? 'relisted' : 'created',
				'previous_item_id' => $previous_item_id,
				// What was actually created, so the next revise reaches for the
				// matching eBay call. Without it a product relisted as an auction
				// still read as fixed-price until an import sync corrected it.
				'listing_type'     => $fixed_price ? 'FixedPriceItem' : 'Chinese',
				// The description it was made with, recorded once saved.
				'desc'             => $this->last_render,
			);
		}
	}

	/**
	 * Add plain words to an eBay error that does not carry any.
	 *
	 * eBay names the XML tag it did not like and stops there. For a category
	 * that is not a bottom-level one that reads as "Input data for tag
	 * &lt;Item.PrimaryCategory.CategoryID&gt; is invalid or missing", which tells a
	 * shop owner nothing at all - and it was the first thing one of them hit.
	 *
	 * The original text is kept: it is what they would quote to us, and what
	 * we would search the logs for.
	 *
	 * @param string $message eBay error message.
	 * @return string
	 */
	public static function explain_ebay_error( $message ) {
		$message = (string) $message;

		if ( false !== stripos( $message, 'PrimaryCategory.CategoryID' ) ) {
			return $message . ' ' . __( 'eBay accepts only a bottom-level category - one with nothing inside it. Open Browse eBay Categories and keep going down until there is nothing left to choose.', 'tcgiant-sync' );
		}

		return $message;
	}

	/**
	 * Does this eBay error mean the listing has ended?
	 *
	 * Ended listings cannot be revised, and their format cannot be changed, so
	 * the only way forward is a new listing. Detected from eBay's own response
	 * rather than local status, which means a listing that is genuinely still
	 * live can never be duplicated by mistake.
	 *
	 * Matched on message text because eBay returns several distinct error codes
	 * for this condition depending on the listing format.
	 *
	 * @param WP_Error $error Error returned by ReviseItem.
	 * @return bool
	 */
	public static function is_ended_listing_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$message = strtolower( $error->get_error_message() );

		$patterns = array(
			'not allowed to revise ended listing',
			'revise ended listing',
			'listing has ended',
			'ended listing',
			'auction has already been closed',
			'already been closed',
		);

		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $message, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the inner <Item> XML content (without the outer <Item> tags).
	 *
	 * Always a new listing's item, photos included: Verify and Check with eBay
	 * send it to VerifyAddItem or VerifyAddFixedPriceItem, which check an add.
	 *
	 * @param WC_Product $product  The WooCommerce product.
	 * @param array      $settings Merged export settings.
	 * @return string XML string.
	 */
	public function build_item_xml_public( WC_Product $product, array $settings ) {
		// A build of its own: its photos are decided afresh.
		$this->pictures_for = array();
		$this->grade_said   = array();

		return $this->build_item_xml( $product, $settings );
	}

	/**
	 * Build the inner <Item> XML content (without the outer <Item> tags).
	 *
	 * @param WC_Product $product  The WooCommerce product.
	 * @param array      $settings Merged export settings.
	 * @return string XML string.
	 */
	/**
	 * Whether an ended listing's product may go back on eBay with the stock it
	 * holds.
	 *
	 * Before a relist, or a push that has found its listing ended and is about
	 * to create a new one:
	 *
	 * - A product the settle before 3.27.0 put back in stock after a website
	 *   sale is refused, with its order named. Relisting it would sell twice
	 *   the coin a website buyer already has.
	 * - If this listing's own sales are not in the stock yet, they are brought
	 *   in first - lowering only - and the product is refused if nothing is
	 *   left. That is a sale the sync has not reached yet.
	 * - If they are already in the stock, whatever it holds now was entered
	 *   since, and it is the shop's to list. The listing's old figures are not
	 *   applied again: they once wiped a restock and blocked relisting for good.
	 *
	 * Never removes the product. A relist or a push is a request to list it.
	 *
	 * @param WC_Product $product      Product.
	 * @param string     $ebay_item_id The ended listing.
	 * @param array|null $item         eBay's GetItem answer for it, if in hand.
	 * @param array|null $answer       Set to that answer, or the one asked for
	 *                                 here, when there was one - so a push can
	 *                                 judge the listing's site from it.
	 * @return bool|WP_Error True if the stock was brought down and should be
	 *                       read again, false if untouched, or why not.
	 */
	private function ended_listing_check( WC_Product $product, $ebay_item_id, $item = null, &$answer = null ) {
		$product_id = $product->get_id();

		$order_id = TCGiant_Sync_Inventory::restocked_after_website_sale( $product_id );
		if ( $order_id ) {
			return new WP_Error( 'relist_refused', sprintf(
				/* translators: %d: order number */
				__( 'it looks as though it sold on your website (order #%d) and an older version of this plugin put it back in stock. On the Stock Review page, set it to 0 if it has gone, or keep its stock if it is real - then it can be listed again', 'tcgiant-sync' ),
				$order_id
			) );
		}

		if ( '' === (string) $ebay_item_id || $product->is_type( 'variable' )
			|| TCGiant_Sync_Inventory::listing_already_settled( $product_id, $ebay_item_id ) ) {
			return false;
		}

		if ( null === $item ) {
			$detail = TCGiant_Sync_API::instance()->get_item( $ebay_item_id );
			if ( is_wp_error( $detail ) || empty( $detail['Item'] ) ) {
				return new WP_Error( 'relist_unchecked', sprintf(
					/* translators: 1: eBay item ID, 2: error */
					__( 'could not ask eBay whether listing %1$s sold (%2$s), so it was not listed again', 'tcgiant-sync' ),
					$ebay_item_id,
					is_wp_error( $detail ) ? $detail->get_error_message() : __( 'no answer', 'tcgiant-sync' )
				) );
			}
			$item = $detail['Item'];
		}

		$answer = $item;

		$listed = $item['Quantity'] ?? null;
		$sold   = $item['SellingStatus']['QuantitySold'] ?? null;

		// eBay sold nothing on this listing, so there is no sale to bring in,
		// and the stock is the shop's - a website sale, or a restock since.
		if ( null !== $sold && ! is_array( $sold ) && 0 === (int) $sold ) {
			return false;
		}

		$before  = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
		$settled = TCGiant_Sync_Inventory::apply_ended_listing_stock( $product_id, $listed, $sold );

		// Never list on a figure that could not be checked: eBay gave no
		// numbers, or the stock write did not take.
		if ( null === $settled ) {
			return new WP_Error( 'relist_unchecked', sprintf(
				/* translators: %s: eBay item ID */
				__( 'the sales on eBay listing %s could not be brought into the stock, so it was not listed again. The activity log says why', 'tcgiant-sync' ),
				$ebay_item_id
			) );
		}

		if ( null !== $before && $settled < $before ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'WC #%d stock lowered from %d to %d before listing it again: eBay sold %d of %d on listing %s, and the sync had not brought that in yet. If you restocked since, set the stock again and relist.',
				$product_id,
				$before,
				$settled,
				(int) $sold,
				(int) $listed,
				$ebay_item_id
			) );
		}

		$now = wc_get_product( $product_id );

		if ( ! $now || ! $now->is_in_stock() || ( $now->managing_stock() && (int) $now->get_stock_quantity() < 1 ) ) {
			return new WP_Error( 'relist_refused', sprintf(
				/* translators: 1: quantity listed, 2: quantity sold */
				__( 'it sold out on eBay (%1$d listed, %2$d sold), so its stock was set from that instead', 'tcgiant-sync' ),
				(int) $listed,
				(int) $sold
			) );
		}

		return true;
	}

	/**
	 * Relist a product's ended eBay listing - if it is right to.
	 *
	 * The one path for bulk Relist and auto-relist. Bulk Relist had no checks
	 * at all: select-all on the Ended tab relisted every coin that had sold,
	 * at its original quantity, and trashed products too. Auto-relist checked
	 * WooCommerce's stock but not eBay's, and sent no quantity, so eBay relisted
	 * at whatever it had left.
	 *
	 * Refuses unless the product is published, has stock to sell, and has no
	 * push under way or unfinished; makes sure that stock is real
	 * (ended_listing_check()); relists through the call eBay says the
	 * listing's format needs, at the shop's quantity; and records the new
	 * listing's times, so the hourly check does not mark a fresh relist ended
	 * because the old end date passed.
	 *
	 * @param int    $product_id Product.
	 * @param string $source     For the log, e.g. "Bulk relist".
	 * @return array|WP_Error item_id and previous_item_id, or why not -
	 *                        'relist_refused' when it was right not to.
	 */
	public function relist_ended_listing( $product_id, $source ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			return new WP_Error( 'relist_refused', __( 'the product is not published', 'tcgiant-sync' ) );
		}

		$ebay_item_id = (string) $product->get_meta( '_ebay_item_id' );
		if ( '' === $ebay_item_id ) {
			return new WP_Error( 'relist_refused', __( 'it has no eBay listing', 'tcgiant-sync' ) );
		}

		// Not while a push of it is under way, or did not finish. A push that
		// finds this listing ended makes a new one in its place, and one that
		// died after eBay made it leaves the product linked here, still Ended.
		// Auto-relist and bulk Relist then relisted this one too: the product
		// was live on eBay twice, and its notice - Unlink this listing, Link
		// the new one - pointed away from the listing it was now linked to.
		if ( '' !== self::push_progress( $product_id ) ) {
			return new WP_Error( 'relist_refused', __( 'its last push is still under way or did not finish, and may already have made a new listing in place of this one - its eBay tab says what to do', 'tcgiant-sync' ) );
		}

		if ( ! $product->is_in_stock() || ( $product->managing_stock() && (int) $product->get_stock_quantity() < 1 ) ) {
			return new WP_Error( 'relist_refused', __( 'it has no stock to sell', 'tcgiant-sync' ) );
		}

		// Never put a listing back up on another eBay site, when the shop imports
		// only its own (TCGiant_Sync_Sites). Refused from the site recorded on the
		// product, before eBay is asked anything: auto-relist goes through its
		// ended products every day, and a GetItem for each other-site product,
		// every day, would spend calls every shop shares for an answer already
		// known. Then on eBay's answer below, whatever was recorded.
		//
		// Whoever's product it is, as for a push. A copy revised once by Push -
		// a select-all on the Listings screen is enough - carries the same marks
		// as a product the shop pushed itself (is_shops_own()), and sparing
		// those put the copy back up on its own site, beside the one Web
		// Interpret makes. A shop that has changed its Primary eBay Marketplace
		// is told why its old site's listings are not relisted instead.
		$recorded_site = (string) $product->get_meta( '_ebay_site' );
		if ( TCGiant_Sync_Sites::leaves_out( $recorded_site ) ) {
			return $this->other_site_relist_refusal( $recorded_site );
		}

		$api    = TCGiant_Sync_API::instance();
		$detail = $api->get_item( $ebay_item_id );

		if ( is_wp_error( $detail ) || empty( $detail['Item'] ) ) {
			return new WP_Error( 'relist_unchecked', sprintf(
				/* translators: 1: eBay item ID, 2: error */
				__( 'could not ask eBay whether listing %1$s sold (%2$s), so it was not relisted', 'tcgiant-sync' ),
				$ebay_item_id,
				is_wp_error( $detail ) ? $detail->get_error_message() : __( 'no answer', 'tcgiant-sync' )
			) );
		}

		$item   = $detail['Item'];
		$status = (string) ( $item['SellingStatus']['ListingStatus'] ?? '' );

		if ( 'Active' === $status ) {
			update_post_meta( $product_id, '_ebay_listing_status', 'Active' );
			TCGiant_Sync_DB::upsert( array(
				'product_id'     => $product_id,
				'ebay_item_id'   => $ebay_item_id,
				'listing_status' => 'Active',
				'last_synced'    => current_time( 'mysql' ),
			) );
			return new WP_Error( 'relist_refused', __( 'its eBay listing is still live', 'tcgiant-sync' ) );
		}

		$check = $this->ended_listing_check( $product, $ebay_item_id, $item );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( $check ) {
			$product = wc_get_product( $product_id );
		}

		// The site, from eBay's answer, every time: it covers a product with no
		// site recorded, and one recorded wrongly - a product linked by hand to
		// another listing, say. No extra call; the answer is in hand. After the
		// check above, so the listing's own sales are still brought into the
		// stock from the answer already paid for, and recorded, so the next
		// refusal comes before any call and auto-relist no longer picks it.
		$listed_on = TCGiant_Sync_Sites::site_of( $item );
		TCGiant_Sync_Sites::record_site( $product_id, $listed_on );
		if ( TCGiant_Sync_Sites::leaves_out( $listed_on ) ) {
			return $this->other_site_relist_refusal( $listed_on );
		}

		// The call the listing's own format needs, as eBay reports it. Local
		// records of the format can be wrong; eBay's answer is in hand.
		$type  = TCGiant_Sync_API::scalar_value( $item['ListingType'] ?? '' );
		$fixed = '' !== $type ? 'Chinese' !== $type : $this->uses_fixed_price_calls( $product, array(), $ebay_item_id );
		$quantity = null;

		// An auction is always one item; a variation listing carries its own
		// quantities. Otherwise the shop's figure, or its default when it does
		// not track stock.
		if ( $fixed && ! $product->is_type( 'variable' ) ) {
			$settings = $this->get_export_settings( $product_id );
			$stock    = $product->get_stock_quantity();
			$quantity = null !== $stock ? max( 1, (int) $stock ) : max( 1, (int) ( $settings['default_quantity'] ?? 1 ) );
		}

		$result = $fixed
			? $api->relist_fixed_price_item( $ebay_item_id, $quantity )
			: $api->relist_item( $ebay_item_id, $quantity );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id = TCGiant_Sync_API::scalar_value( $result['ItemID'] ?? '' );
		if ( '' === $new_id ) {
			return new WP_Error( 'no_item_id', __( 'eBay did not return a new Item ID. The listing may have been relisted - check your eBay account.', 'tcgiant-sync' ) );
		}

		update_post_meta( $product_id, '_ebay_item_id', $new_id );
		update_post_meta( $product_id, '_ebay_listing_status', 'Active' );
		// RelistItem keeps the description eBay had, so the record of what was
		// sent follows the listing to its new number.
		if ( (string) get_post_meta( $product_id, '_tcgiant_tpl_item', true ) === (string) $ebay_item_id ) {
			update_post_meta( $product_id, '_tcgiant_tpl_item', $new_id );
		}

		$end_time = TCGiant_Sync_API::scalar_value( $result['EndTime'] ?? '' );
		if ( '' !== $end_time ) {
			update_post_meta( $product_id, '_ebay_end_time', $end_time );
		} else {
			delete_post_meta( $product_id, '_ebay_end_time' );
		}

		$start_time = TCGiant_Sync_API::scalar_value( $result['StartTime'] ?? '' );
		update_post_meta( $product_id, '_ebay_start_time', '' !== $start_time ? $start_time : gmdate( 'Y-m-d\TH:i:s.000\Z' ) );

		// A new listing: what was settled about the old one no longer applies.
		TCGiant_Sync_Inventory::clear_settled_mark( $product_id );
		delete_transient( 'tcgiant_restocked_after_sale' );

		TCGiant_Sync_DB::upsert( array(
			'product_id'     => $product_id,
			'ebay_item_id'   => $new_id,
			'listing_status' => 'Active',
			'last_synced'    => current_time( 'mysql' ),
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'%s: WC #%d relisted on eBay as %s (was %s)%s.',
			$source,
			$product_id,
			$new_id,
			$ebay_item_id,
			null !== $quantity ? sprintf( ', quantity %d', $quantity ) : ''
		), 'success' );

		return array(
			'item_id'          => $new_id,
			'previous_item_id' => $ebay_item_id,
		);
	}

	/**
	 * Why a listing on another eBay site is not relisted.
	 *
	 * 'relist_refused', so auto-relist counts it as passed over and bulk Relist
	 * shows the reason beside the product, as for every other right refusal.
	 *
	 * @param string $site The listing's Item.Site.
	 * @return WP_Error
	 */
	private function other_site_relist_refusal( $site ) {
		return new WP_Error( 'relist_refused', sprintf(
			/* translators: 1: an eBay site, e.g. "eBay United Kingdom", 2: the shop's own, e.g. "eBay United States" */
			__( 'its eBay listing is on %1$s, and Import listings from is set to %2$s only', 'tcgiant-sync' ),
			TCGiant_Sync_Sites::label( $site ),
			TCGiant_Sync_Sites::primary_label()
		) );
	}

	/**
	 * Whether this listing must go through eBay's fixed-price calls.
	 *
	 * @param WC_Product $product      Product being pushed.
	 * @param array      $settings     Resolved export settings.
	 * @param string     $ebay_item_id Existing listing, when there is one.
	 * @return bool
	 */
	public function uses_fixed_price_calls( WC_Product $product, array $settings = array(), $ebay_item_id = '' ) {
		// Variations only exist on fixed-price listings, and eBay will not accept
		// them through ReviseItem at all — it ignores them silently.
		if ( $product->is_type( 'variable' ) ) {
			return true;
		}

		// For a listing that already exists, what it actually is beats what we
		// would create today: the seller may have changed its format on eBay.
		if ( '' !== (string) $ebay_item_id ) {
			$actual = (string) $product->get_meta( '_ebay_listing_type' );
			if ( '' !== $actual ) {
				return 'FixedPriceItem' === $actual;
			}
		}

		return 'FixedPriceItem' === ( $settings['listing_type'] ?? 'FixedPriceItem' );
	}

	/**
	 * Build the inner <Item> XML content (without the outer <Item> tags).
	 *
	 * @param WC_Product $product  The WooCommerce product.
	 * @param array      $settings Merged export settings.
	 * @param bool       $revise   Whether the item goes to ReviseItem or
	 *                             ReviseFixedPriceItem, for a live listing.
	 * @return string XML string.
	 */
	private function build_item_xml( WC_Product $product, array $settings, $revise = false ) {
		$this->last_render = null;

		$title             = $this->sanitize_title( $product->get_name() );
		$rendered          = $this->build_listing_description( $product, $settings, $title );
		$description       = $rendered['html'];
		$this->last_render = $rendered;
		$price       = wc_format_decimal( $product->get_regular_price(), 2 );
		// How many to offer.
		//
		// When the shop tracks stock, that figure decides it, floored at 1 so
		// VerifyAddItem still gets valid XML for a zero-stock product. do_push()
		// blocks the real listing separately - do not rely on this clamp to
		// prevent overselling.
		//
		// When the shop does NOT track stock, get_stock_quantity() returns null,
		// which fell through the same clamp and listed exactly one unit. For a
		// card shop selling singles that is right and is why it went unnoticed.
		// For anyone holding multiples it is not: a distributor with forty
		// switches listed one and sold out immediately, with nothing to say so.
		// Worse, the out-of-stock guard in do_push() only fires when stock IS
		// managed, and its own message suggests turning stock management off as
		// the way to list - which walked people straight into this.
		//
		// The setting defaults to 1, so nothing changes for anyone until they
		// say otherwise.
		// Ask for the figure first, and only fall back when there is none.
		//
		// Branching on managing_stock() was wrong: it returns false for EVERY
		// product when the shop-wide stock option is off, even where a real
		// per-product quantity is stored. That threw away a good figure and
		// listed the fallback instead - the same fault as before, pointed at a
		// different set of shops.
		$stock = $product->get_stock_quantity();

		if ( null !== $stock && '' !== $stock ) {
			$quantity = max( 1, (int) $stock );
		} else {
			$quantity = max( 1, (int) ( $settings['default_quantity'] ?? 1 ) );
		}

		$sku         = $product->get_sku();
		$images      = $this->get_product_image_urls( $product, $revise );

		// A photo left out because the listing's others are on eBay would come
		// off the live listing with a revise, which replaces the photos with
		// those it is sent. So that revise is sent none: eBay's Revise calls
		// change only what they are sent, and the listing keeps its own.
		$this->pictures_held = $revise ? ( $this->pictures_for[ $product->get_id() ]['left_out'] ?? array() ) : array();
		if ( $this->pictures_held ) {
			$images = array();
		}

		// Build picture URLs XML. No photos, no PictureDetails at all: an empty
		// one would tell eBay the listing has none.
		$picture_xml = '';
		if ( ! empty( $images ) ) {
			$picture_xml = '<PictureDetails>' . "\n";
			foreach ( array_slice( $images, 0, self::MAX_IMAGES ) as $url ) {
				$picture_xml .= "\t<PictureURL>" . esc_url( $url ) . "</PictureURL>\n";
			}
			$picture_xml .= '</PictureDetails>';
		}

		// Build seller profiles XML (business policies).
		$profiles_xml = '';
		if ( ! empty( $settings['fulfillment_policy_id'] ) || ! empty( $settings['return_policy_id'] ) || ! empty( $settings['payment_policy_id'] ) ) {
			$profiles_xml = '<SellerProfiles>' . "\n";
			if ( ! empty( $settings['fulfillment_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerShippingProfile>\n\t\t<ShippingProfileID>" . esc_attr( $settings['fulfillment_policy_id'] ) . "</ShippingProfileID>\n\t</SellerShippingProfile>\n";
			}
			if ( ! empty( $settings['return_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerReturnProfile>\n\t\t<ReturnProfileID>" . esc_attr( $settings['return_policy_id'] ) . "</ReturnProfileID>\n\t</SellerReturnProfile>\n";
			}
			if ( ! empty( $settings['payment_policy_id'] ) ) {
				$profiles_xml .= "\t<SellerPaymentProfile>\n\t\t<PaymentProfileID>" . esc_attr( $settings['payment_policy_id'] ) . "</PaymentProfileID>\n\t</SellerPaymentProfile>\n";
			}
			$profiles_xml .= '</SellerProfiles>';
		}

		$xml  = '<Title>' . esc_xml( $title ) . '</Title>' . "\n";
		// Split any literal "]]>" in the description so it cannot terminate the
		// CDATA section early. Unescaped, a description containing that
		// sequence (common in code snippets) either breaks the request or
		// injects raw XML into the AddItem payload.
		$xml .= '<Description><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $description ) . ']]></Description>' . "\n";
		$xml .= '<PrimaryCategory><CategoryID>' . esc_attr( $settings['category_id'] ) . '</CategoryID></PrimaryCategory>' . "\n";
		
	
		if ( ! $product->is_type( 'variable' ) ) {
			// Auctions are always quantity 1 on eBay.
			$is_auction = 'Chinese' === ( $settings['listing_type'] ?? 'FixedPriceItem' );
			$qty = $is_auction ? 1 : (int) $quantity;
			$xml .= '<StartPrice>' . esc_attr( $price ) . '</StartPrice>' . "\n";
			$xml .= '<Quantity>' . $qty . '</Quantity>' . "\n";
			if ( ! empty( $sku ) ) {
				$xml .= '<SKU>' . esc_xml( $sku ) . '</SKU>' . "\n";
			}
		}

		$xml .= '<CategoryMappingAllowed>true</CategoryMappingAllowed>' . "\n";

		// Condition: use new ConditionDescriptor system for eligible categories,
		// fall back to legacy ConditionID for everything else.
		$descriptor_xml = $this->build_condition_descriptors_xml( $settings, $product->get_id() );
		if ( ! empty( $descriptor_xml ) ) {
			// ConditionID is set inside build_condition_descriptors_xml based on type.
			$condition_id = 'graded' === $settings['condition_type'] ? '2750' : '4000';
			$xml .= '<ConditionID>' . esc_attr( $condition_id ) . '</ConditionID>' . "\n";
			$xml .= $descriptor_xml . "\n";
		} else {
			$xml .= '<ConditionID>' . esc_attr( $settings['condition_id'] ) . '</ConditionID>' . "\n";
		}

		// ItemSpecifics: the product's WooCommerce attributes, plus the
		// configured grading/coin fields.
		$item_specifics_xml = $this->build_item_specifics_xml( $product, $settings );
		if ( ! empty( $item_specifics_xml ) ) {
			$xml .= $item_specifics_xml . "\n";
		}

		// Derive Country, Currency, and Site from the configured marketplace.
		$marketplace = TCGiant_Sync_API::instance()->get_marketplace_config();

		$xml .= '<Country>' . esc_attr( $marketplace['country'] ) . '</Country>' . "\n";
		if ( ! empty( $settings['location'] ) ) {
			$xml .= '<Location>' . esc_xml( $settings['location'] ) . '</Location>' . "\n";
		}
		if ( ! empty( $settings['postal_code'] ) ) {
			$xml .= '<PostalCode>' . esc_xml( $settings['postal_code'] ) . '</PostalCode>' . "\n";
		}
		$xml .= '<Currency>' . esc_attr( $marketplace['currency'] ) . '</Currency>' . "\n";
		$xml .= '<DispatchTimeMax>3</DispatchTimeMax>' . "\n";
		$xml .= $this->build_shipping_package_xml( $product, $settings );

		// Listing type and duration: default to FixedPriceItem/GTC, overridable per-product.
		$listing_type     = $settings['listing_type'] ?? 'FixedPriceItem';
		$listing_duration = $settings['listing_duration'] ?? 'GTC';

		// Validate duration is compatible with listing type.
		$valid_durations = TCGiant_Sync_Catalog::DURATIONS_BY_TYPE[ $listing_type ] ?? array( 'GTC' );
		if ( ! in_array( $listing_duration, $valid_durations, true ) ) {
			$listing_duration = $valid_durations[0]; // Fall back to first valid duration.
		}

		$xml .= '<ListingDuration>' . esc_attr( $listing_duration ) . '</ListingDuration>' . "\n";
		$xml .= '<ListingType>' . esc_attr( $listing_type ) . '</ListingType>' . "\n";
		$xml .= '<Site>' . esc_attr( $marketplace['site'] ) . '</Site>' . "\n";

		if ( $product instanceof WC_Product_Variable ) {
			$xml .= '<Variations>' . "\n";
			$xml .= "\t<VariationSpecificsSet>\n";
			foreach ( $this->variation_specifics_set( $product ) as list( $label, $values ) ) {
				$xml .= "\t\t<NameValueList>\n";
				$xml .= "\t\t\t<Name>" . esc_xml( $label ) . "</Name>\n";
				foreach ( $values as $val ) {
					$xml .= "\t\t\t<Value>" . esc_xml( $val ) . "</Value>\n";
				}
				$xml .= "\t\t</NameValueList>\n";
			}
			$xml .= "\t</VariationSpecificsSet>\n";
			
			$children_ids = $product->get_children();
			foreach ( $children_ids as $child_id ) {
				$child = wc_get_product( $child_id );
				// get_children() on a variable product yields variations, but
				// guard explicitly so get_variation_attributes() is safe.
				if ( ! $child instanceof WC_Product_Variation || 'publish' !== $child->get_status() ) continue;
				
				$xml .= "\t<Variation>\n";
				$xml .= "\t\t<SKU>" . esc_xml( $child->get_sku() ) . "</SKU>\n";
				$xml .= "\t\t<StartPrice>" . esc_attr( wc_format_decimal( $child->get_regular_price(), 2 ) ) . "</StartPrice>\n";
				$xml .= "\t\t<Quantity>" . max( 0, (int) $child->get_stock_quantity() ) . "</Quantity>\n";
				
				$xml .= "\t\t<VariationSpecifics>\n";
				$child_attrs = $child->get_variation_attributes();
				foreach ( $child_attrs as $key => $val ) {
					$attr_name = str_replace( 'attribute_', '', $key );
					$label = wc_attribute_label( $attr_name );
					$term = get_term_by( 'slug', $val, $attr_name );
					$val_name = $term ? $term->name : $val;
					
					$xml .= "\t\t\t<NameValueList>\n";
					$xml .= "\t\t\t\t<Name>" . esc_xml( $label ) . "</Name>\n";
					$xml .= "\t\t\t\t<Value>" . esc_xml( $val_name ) . "</Value>\n";
					$xml .= "\t\t\t</NameValueList>\n";
				}
				$xml .= "\t\t</VariationSpecifics>\n";
				$xml .= "\t</Variation>\n";
			}
			$xml .= "</Variations>\n";
		}

		if ( $picture_xml ) {
			$xml .= $picture_xml . "\n";
		}

		if ( $profiles_xml ) {
			$xml .= $profiles_xml . "\n";
		}

		// Best Offer support (FixedPriceItem only).
		if ( 'FixedPriceItem' === $listing_type ) {
			$best_offer_enabled = ! empty( $settings['best_offer_enabled'] );
			$product_bo         = $product->get_meta( '_tcgiant_best_offer_enabled' );
			// Per-product override.
			if ( '' !== $product_bo ) {
				$best_offer_enabled = (bool) $product_bo;
			}

			if ( $best_offer_enabled ) {
				$xml .= '<BestOfferDetails>' . "\n";
				$xml .= "\t<BestOfferEnabled>true</BestOfferEnabled>\n";
				$xml .= '</BestOfferDetails>' . "\n";

				// Auto-accept/decline thresholds.
				$auto_accept  = $settings['best_offer_auto_accept'] ?? '';
				$auto_decline = $settings['best_offer_auto_decline'] ?? '';

				// Allow per-product override.
				$product_accept  = $product->get_meta( '_tcgiant_bo_auto_accept' );
				$product_decline = $product->get_meta( '_tcgiant_bo_auto_decline' );
				if ( '' !== $product_accept ) {
					$auto_accept = $product_accept;
				}
				if ( '' !== $product_decline ) {
					$auto_decline = $product_decline;
				}

				$bo_xml = '<ListingDetails>' . "\n";
				if ( ! empty( $auto_accept ) && is_numeric( $auto_accept ) ) {
					$accept_price = (float) $auto_accept > 1 ? $auto_accept : wc_format_decimal( (float) $price * (float) $auto_accept, 2 );
					$bo_xml .= "\t<MinimumBestOfferPrice>" . esc_attr( $accept_price ) . "</MinimumBestOfferPrice>\n";
				}
				if ( ! empty( $auto_decline ) && is_numeric( $auto_decline ) ) {
					$decline_price = (float) $auto_decline > 1 ? $auto_decline : wc_format_decimal( (float) $price * (float) $auto_decline, 2 );
					$bo_xml .= "\t<BestOfferAutoDeclinePrice>" . esc_attr( $decline_price ) . "</BestOfferAutoDeclinePrice>\n";
				}
				$bo_xml .= '</ListingDetails>' . "\n";
				$xml .= $bo_xml;
			}
		}

		// GPSR (EU General Product Safety Regulation) compliance fields.
		$gpsr_enabled = ! empty( $settings['gpsr_enabled'] );
		if ( $gpsr_enabled ) {
			$gpsr_xml = '';
			$gpsr_mfg = $settings['gpsr_manufacturer_name'] ?? '';
			$gpsr_address = $settings['gpsr_manufacturer_address'] ?? '';
			$gpsr_eu_rep = $settings['gpsr_eu_responsible'] ?? '';

			if ( ! empty( $gpsr_mfg ) || ! empty( $gpsr_address ) ) {
				$gpsr_xml .= '<ProductSafety>' . "\n";
				if ( ! empty( $gpsr_mfg ) ) {
					$gpsr_xml .= '<Component>' . "\n";
					$gpsr_xml .= "\t<Type>Manufacturer</Type>\n";
					$gpsr_xml .= "\t<Value>" . esc_xml( $gpsr_mfg ) . "</Value>\n";
					$gpsr_xml .= '</Component>' . "\n";
				}
				if ( ! empty( $gpsr_address ) ) {
					$gpsr_xml .= '<Component>' . "\n";
					$gpsr_xml .= "\t<Type>ManufacturerAddress</Type>\n";
					$gpsr_xml .= "\t<Value>" . esc_xml( $gpsr_address ) . "</Value>\n";
					$gpsr_xml .= '</Component>' . "\n";
				}
				if ( ! empty( $gpsr_eu_rep ) ) {
					$gpsr_xml .= '<Component>' . "\n";
					$gpsr_xml .= "\t<Type>ResponsiblePerson</Type>\n";
					$gpsr_xml .= "\t<Value>" . esc_xml( $gpsr_eu_rep ) . "</Value>\n";
					$gpsr_xml .= '</Component>' . "\n";
				}
				$gpsr_xml .= '</ProductSafety>' . "\n";
				$xml .= $gpsr_xml;
			}
		}

		return $xml;
	}

	// -------------------------------------------------------------------------
	// Settings & Validation
	// -------------------------------------------------------------------------

	/**
	 * Get merged export settings: global defaults overridden by per-product meta.
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return array Merged settings ready for use in build_item_xml().
	 */
	public function get_export_settings( $product_id ) {
		$global   = TCGiant_Sync_OAuth::instance()->get_settings();
		$product  = wc_get_product( $product_id );

		$settings = array(
			'category_id'           => $global['export_category_id'] ?? '',
			'category_name'         => $global['export_category_name'] ?? '',
			'condition_id'          => $global['export_condition_id'] ?? '1000',
			'condition_type'        => $global['export_condition_type'] ?? '',
			'grader_id'             => $global['export_grader_id'] ?? '',
			'grade_value'           => $global['export_grade_value'] ?? '',
			'cert_number'           => $global['export_cert_number'] ?? '',
			'coin_year'             => $global['export_coin_year'] ?? '',
			'ungraded_condition'    => $global['export_ungraded_condition'] ?? '',
			'location'              => $global['export_location'] ?? '',
			'postal_code'           => $global['export_postal_code'] ?? '',
			'fulfillment_policy_id' => $global['export_fulfillment_policy'] ?? '',
			'return_policy_id'      => $global['export_return_policy'] ?? '',
			'payment_policy_id'     => $global['export_payment_policy'] ?? '',
			'listing_type'          => $global['export_listing_type'] ?? 'FixedPriceItem',
			'listing_duration'      => $global['export_listing_duration'] ?? 'GTC',
			'default_quantity'      => max( 1, (int) ( $global['export_default_quantity'] ?? 1 ) ),
			'send_package_details'  => ! empty( $global['export_send_package_details'] ),
		);

		if ( $product ) {
			// Per-product overrides take precedence if set.
			$override_category  = $product->get_meta( '_ebay_export_category_id' );
			$override_condition = $product->get_meta( '_ebay_export_condition_id' );

			if ( ! empty( $override_category ) ) {
				$settings['category_id'] = $override_category;
			}
			if ( ! empty( $override_condition ) ) {
				$settings['condition_id'] = $override_condition;
			}

			// ConditionDescriptor per-product overrides.
			$override_cond_type = $product->get_meta( '_ebay_export_condition_type' );
			if ( ! empty( $override_cond_type ) ) {
				$settings['condition_type'] = $override_cond_type;
			}

			$override_item_type = $product->get_meta( '_ebay_export_item_type' );
			if ( ! empty( $override_item_type ) ) {
				$settings['item_type'] = $override_item_type;
			}

			$override_cat_name = $product->get_meta( '_ebay_export_category_name' );
			if ( ! empty( $override_cat_name ) ) {
				$settings['category_name'] = $override_cat_name;
			}

			$override_grader = $product->get_meta( '_ebay_export_grader_id' );
			if ( ! empty( $override_grader ) ) {
				$settings['grader_id'] = $override_grader;
			}

			$override_grade = $product->get_meta( '_ebay_export_grade_value' );
			if ( ! empty( $override_grade ) ) {
				$settings['grade_value'] = $override_grade;
			}

			$override_cert = $product->get_meta( '_ebay_export_cert_number' );
			if ( ! empty( $override_cert ) ) {
				$settings['cert_number'] = $override_cert;
			}

			$override_coin_year = $product->get_meta( '_ebay_export_coin_year' );
			if ( ! empty( $override_coin_year ) ) {
				$settings['coin_year'] = $override_coin_year;
			}

			$override_ungraded = $product->get_meta( '_ebay_export_ungraded_condition' );
			if ( ! empty( $override_ungraded ) ) {
				$settings['ungraded_condition'] = $override_ungraded;
			}

			// Listing type & duration per-product overrides.
			$override_listing_type = $product->get_meta( '_ebay_export_listing_type' );
			if ( ! empty( $override_listing_type ) ) {
				$settings['listing_type'] = $override_listing_type;
			}

			$override_listing_duration = $product->get_meta( '_ebay_export_listing_duration' );
			if ( ! empty( $override_listing_duration ) ) {
				$settings['listing_duration'] = $override_listing_duration;
			}

			// Per-product shipping/fulfillment policy override.
			$override_fulfillment = $product->get_meta( '_ebay_export_fulfillment_policy' );
			if ( ! empty( $override_fulfillment ) ) {
				$settings['fulfillment_policy_id'] = $override_fulfillment;
			}

			// Item specifics filled in on the product's eBay Listing tab.
			//
			// Held apart from WooCommerce's own attributes deliberately. An
			// attribute is shop-front furniture - it shows on the product page and
			// can be an axis of a variation - and neither is what someone filling in
			// "Type" because eBay asked for it is requesting. Attributes are still
			// read; what is typed here simply beats them.
			$override_specifics = $product->get_meta( '_ebay_export_specifics' );
			if ( is_array( $override_specifics ) && ! empty( $override_specifics ) ) {
				$settings['specifics'] = $override_specifics;
			}
		}

		// The listing template the description is built with: the product's own
		// choice, its WooCommerce category's, the default, or None.
		$settings['template_id']  = 0;
		$settings['template_why'] = 'none';
		if ( $product && class_exists( 'TCGiant_Sync_Templates' ) ) {
			$resolved                 = TCGiant_Sync_Templates::resolve( $product );
			$settings['template_id']  = $resolved['template_id'];
			$settings['template_why'] = $resolved['why'];
		}

		// Flag category-item_type mismatch so validation can catch it.
		// e.g. user picks "Coins" but the global default category is 183050 (TCG).
		$item_type   = $settings['item_type'] ?? '';
		$category_id = $settings['category_id'] ?? '';
		if ( 'coins' === $item_type && in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_TCG, true ) ) {
			$settings['_category_mismatch'] = 'coins_in_tcg';
		} elseif ( 'tcg' === $item_type && in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_COINS, true ) ) {
			$settings['_category_mismatch'] = 'tcg_in_coins';
		}

		return $settings;
	}

	/**
	 * Validate that the minimum required settings for a push are present.
	 *
	 * @param array $settings Export settings array.
	 * @return true|WP_Error True on success, WP_Error describing what's missing.
	 */
	public function validate_export_settings( array $settings ) {
		$missing = array();

		// Check for category-item_type mismatch (set in get_export_settings).
		if ( ! empty( $settings['_category_mismatch'] ) ) {
			if ( 'coins_in_tcg' === $settings['_category_mismatch'] ) {
				$missing[] = __( 'eBay Category — this is a Coins item but the eBay category is set to Trading Cards. Set a Coins leaf category (e.g. "Coins: US > Dollars > Silver Eagles") on this product using Browse eBay Categories in the TCGiant Sync tab', 'tcgiant-sync' );
			} elseif ( 'tcg_in_coins' === $settings['_category_mismatch'] ) {
				$missing[] = __( 'eBay Category — this is a Trading Cards item but the eBay category is set to Coins. Set a TCG leaf category on this product using Browse eBay Categories in the TCGiant Sync tab', 'tcgiant-sync' );
			}
		}

		if ( empty( $settings['category_id'] ) ) {
			$missing[] = __( 'eBay Category ID (set a default in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['location'] ) ) {
			$missing[] = __( 'Item Location (set city/state in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['postal_code'] ) ) {
			$missing[] = __( 'Postal Code (set ZIP code in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['fulfillment_policy_id'] ) ) {
			$missing[] = __( 'Fulfillment (Shipping) Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['return_policy_id'] ) ) {
			$missing[] = __( 'Return Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}
		if ( empty( $settings['payment_policy_id'] ) ) {
			$missing[] = __( 'Payment Policy (fetch policies in TCGiant Sync settings)', 'tcgiant-sync' );
		}

		// Validate ConditionDescriptor fields for eligible categories.
		$item_type = $settings['item_type'] ?? '';
		if ( self::is_descriptor_category( $settings['category_id'], $item_type ) ) {
			if ( empty( $settings['condition_type'] ) ) {
				$missing[] = __( 'Condition Type (select Graded or Ungraded in TCGiant Sync settings — required for this eBay category)', 'tcgiant-sync' );
			} elseif ( 'graded' === $settings['condition_type'] ) {
				if ( empty( $settings['grader_id'] ) ) {
					$missing[] = __( 'Professional Grader (required for graded items)', 'tcgiant-sync' );
				}
				if ( empty( $settings['grade_value'] ) ) {
					$missing[] = __( 'Grade (required for graded items)', 'tcgiant-sync' );
				}
			} elseif ( 'ungraded' === $settings['condition_type'] ) {
				if ( empty( $settings['ungraded_condition'] ) ) {
					$missing[] = __( 'Ungraded Condition (required for ungraded items in this category)', 'tcgiant-sync' );
				}
			}
		}

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'missing_export_settings',
				/* translators: %s: comma-separated list of missing settings */
				sprintf( __( 'Cannot push to eBay — missing required settings: %s', 'tcgiant-sync' ), implode( ', ', $missing ) ),
				array( 'missing' => $missing )
			);
		}

		// Validate listing duration compatibility with listing type.
		$listing_type     = $settings['listing_type'] ?? 'FixedPriceItem';
		$listing_duration = $settings['listing_duration'] ?? 'GTC';
		$valid_durations  = TCGiant_Sync_Catalog::DURATIONS_BY_TYPE[ $listing_type ] ?? array( 'GTC' );

		// Fixed price has exactly one duration eBay honours, so there is nothing
		// a seller could have chosen that is worth refusing a push over. Sites
		// that saved 30 Days while the plugin still offered it are already
		// running Good 'Til Cancelled listings on eBay, whatever we recorded, so
		// the builder corrects the value on the way out and this says nothing.
		// Refusing here instead would stop every push on those sites the moment
		// they updated, over a setting that never had any effect.
		if ( 'FixedPriceItem' === $listing_type ) {
			return true;
		}

		if ( ! in_array( $listing_duration, $valid_durations, true ) ) {
			$type_label = TCGiant_Sync_Catalog::LISTING_TYPES[ $listing_type ] ?? $listing_type;
			$valid_labels = array_map( function( $d ) { return TCGiant_Sync_Catalog::LISTING_DURATIONS[ $d ] ?? $d; }, $valid_durations );
			return new WP_Error(
				'invalid_duration',
				sprintf(
					__( 'Invalid listing duration for %1$s listings. Valid options: %2$s', 'tcgiant-sync' ),
					$type_label,
					implode( ', ', $valid_labels )
				)
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// ItemSpecifics Methods
	// -------------------------------------------------------------------------

	/**
	 * Build <ItemSpecifics> XML for the listing.
	 *
	 * eBay Coins categories require item specifics like "Certification" (grader name)
	 * in addition to the structured ConditionDescriptors. Returns empty string for
	 * non-coin categories or if no relevant data is available.
	 *
	 * @param array $settings Merged export settings.
	 * @return string XML string or empty.
	 */
	/**
	 * eBay limits on item specifics.
	 */
	const MAX_SPECIFICS      = 30;
	const MAX_SPECIFIC_NAME  = 40;
	const MAX_SPECIFIC_VALUE = 65;

	/**
	 * How many of eBay's suggested values for one aspect to offer as choices.
	 * Some categories publish hundreds; a dropdown that long is no help, and the
	 * whole list would travel to the browser on every check.
	 */
	const MAX_ASPECT_CHOICES = 60;

	/**
	 * Collect item specifics from a product's WooCommerce attributes.
	 *
	 * The importer maps eBay item specifics into product attributes, but until
	 * now nothing mapped them back on push — so listings created from
	 * WooCommerce went up with almost no item specifics, which eBay penalises
	 * in search and, for required aspects, rejects outright.
	 *
	 * @param WC_Product $product Product being listed.
	 * @return array<string,string> Name => value.
	 */
	private function collect_attribute_specifics( WC_Product $product ) {
		$specifics = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			// Variation axes are described per-variation, not as item specifics.
			if ( $attribute->get_variation() ) {
				continue;
			}

			$name = wc_attribute_label( $attribute->get_name(), $product );
			if ( '' === trim( (string) $name ) ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}

			$values = array_filter( array_map( 'trim', (array) $values ) );
			if ( empty( $values ) ) {
				continue;
			}

			// eBay accepts repeated names for multi-value aspects, but a single
			// joined value is safer across categories with MULTI disabled.
			$value = implode( ', ', $values );

			$name  = mb_substr( $name, 0, self::MAX_SPECIFIC_NAME );
			$value = mb_substr( $value, 0, self::MAX_SPECIFIC_VALUE );

			$specifics[ $name ] = $value;
		}

		return $specifics;
	}

	/**
	 * Check a product against the aspects eBay requires for its category.
	 *
	 * @param WC_Product $product  Product being listed.
	 * @param array      $settings Merged export settings.
	 * @return string[] Names of required aspects with no value. Empty when fine.
	 */
	public function find_missing_required_aspects( WC_Product $product, array $settings ) {
		$category_id = $settings['category_id'] ?? '';
		if ( empty( $category_id ) ) {
			return array();
		}

		$aspects = TCGiant_Sync_API::instance()->get_category_aspects( $category_id );
		if ( is_wp_error( $aspects ) || empty( $aspects ) ) {
			// Never block a push because eBay's metadata call failed — that
			// would turn an eBay outage into an inability to list anything.
			return array();
		}

		$provided = array();
		foreach ( $this->build_specifics_map( $product, $settings ) as $name => $value ) {
			$provided[ self::normalize_aspect_name( $name ) ] = $value;
		}

		$missing = array();
		foreach ( $aspects as $aspect ) {
			if ( empty( $aspect['required'] ) || empty( $aspect['name'] ) ) {
				continue;
			}
			$key = self::normalize_aspect_name( $aspect['name'] );
			if ( ! isset( $provided[ $key ] ) || '' === trim( (string) $provided[ $key ] ) ) {
				$missing[] = $aspect['name'];
			}
		}

		return $missing;
	}

	/**
	 * Build the full name => value map of item specifics for a product.
	 *
	 * Product attributes first, then the grading/coin fields, which win on a
	 * clash because they are explicitly configured for this listing.
	 *
	 * @param WC_Product $product  Product being listed.
	 * @param array      $settings Merged export settings.
	 * @return array<string,string>
	 */
	private function build_specifics_map( WC_Product $product, array $settings ) {
		$specifics = array_merge(
			$this->collect_attribute_specifics( $product ),
			$this->collect_configured_specifics( $settings ),
			$this->collect_entered_specifics( $settings )
		);

		$specifics = $this->add_derived_specifics( $specifics, $settings );

		return $this->canonicalize_specific_names( $specifics, $settings );
	}

	/**
	 * Item specifics typed into the product's eBay Listing tab.
	 *
	 * Last in the merge, so a value entered against this listing beats both a
	 * product attribute of the same name and anything derived from the grading
	 * fields. Someone typing into the box eBay's own rejection pointed them at
	 * means it.
	 *
	 * @param array $settings Merged export settings.
	 * @return array<string,string>
	 */
	private function collect_entered_specifics( array $settings ) {
		$entered = $settings['specifics'] ?? array();
		if ( ! is_array( $entered ) ) {
			return array();
		}

		$out = array();
		foreach ( $entered as $name => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$name  = trim( (string) $name );
			$value = trim( (string) $value );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$out[ mb_substr( $name, 0, self::MAX_SPECIFIC_NAME ) ] = mb_substr( $value, 0, self::MAX_SPECIFIC_VALUE );
		}

		return $out;
	}

	/**
	 * What eBay wants for this category, and what we would send it.
	 *
	 * The plugin has always known this and never shown it. A merchant selling
	 * networking gear was told by eBay that the item specific Type was missing,
	 * and wrote back: not sure where I would find what type is missing, do not
	 * see that in the settings or product when editing the product. There was
	 * nowhere to look. The answer was one cached API call away and no screen
	 * ever asked for it. This is that screen's data.
	 *
	 * A failed lookup is its own status rather than "nothing required". The
	 * push deliberately treats those two the same, so that an eBay outage
	 * cannot stop anyone listing - but a panel doing the same would be telling
	 * merchants everything was fine at the one moment it cannot know.
	 *
	 * @param WC_Product $product  Product being described.
	 * @param array      $settings Merged export settings.
	 * @return array
	 */
	public function describe_aspects( WC_Product $product, array $settings ) {
		$category_id = (string) ( $settings['category_id'] ?? '' );

		if ( '' === trim( $category_id ) ) {
			return array(
				'status'      => 'no_category',
				'message'     => __( 'Choose an eBay category first - which item specifics are required depends on it.', 'tcgiant-sync' ),
				'category_id' => '',
				'aspects'     => array(),
				'extra'       => array(),
			);
		}

		$aspects = TCGiant_Sync_API::instance()->get_category_aspects( $category_id );

		if ( is_wp_error( $aspects ) ) {
			return array(
				'status'      => 'lookup_failed',
				'message'     => $aspects->get_error_message(),
				'category_id' => $category_id,
				'aspects'     => array(),
				'extra'       => array(),
			);
		}

		// Where each value would come from, so the panel can say so rather than
		// leaving someone to wonder why a box they never filled in is satisfied.
		$buckets = array(
			'attribute' => $this->collect_attribute_specifics( $product ),
			'listing'   => $this->collect_configured_specifics( $settings ),
			'entered'   => $this->collect_entered_specifics( $settings ),
		);

		$sources = array();
		foreach ( $buckets as $source => $bucket ) {
			foreach ( $bucket as $name => $value ) {
				if ( '' === trim( (string) $value ) ) {
					continue;
				}
				// Later buckets win, matching the merge order in build_specifics_map().
				$sources[ self::normalize_aspect_name( $name ) ] = array( $source, $name );
			}
		}

		$provided = array();
		$display  = array();
		foreach ( $this->build_specifics_map( $product, $settings ) as $name => $value ) {
			$key              = self::normalize_aspect_name( $name );
			$provided[ $key ] = (string) $value;
			// The name as it would be sent. Without this, anything no bucket owns
			// - a derived value, say - was reported under its comparison key,
			// so the panel offered the merchant "circulateduncirculated".
			$display[ $key ]  = (string) $name;
		}

		$required = array();
		$optional = array();
		$listed   = array();

		foreach ( $aspects as $aspect ) {
			if ( empty( $aspect['name'] ) ) {
				continue;
			}

			$key            = self::normalize_aspect_name( $aspect['name'] );
			$listed[ $key ] = true;
			$value          = isset( $provided[ $key ] ) ? $provided[ $key ] : '';
			$origin         = ( '' !== trim( $value ) && isset( $sources[ $key ] ) ) ? $sources[ $key ] : array( '', '' );
			$offered        = array_values( (array) ( $aspect['values'] ?? array() ) );

			$row = array(
				'name'      => (string) $aspect['name'],
				'required'  => ! empty( $aspect['required'] ),
				'mode'      => (string) ( $aspect['mode'] ?? 'FREE_TEXT' ),
				'multi'     => ! empty( $aspect['multi'] ),
				'choices'   => array_slice( $offered, 0, self::MAX_ASPECT_CHOICES ),
				// A closed list we had to cut is not a closed list any more. The
				// panel offers free text in that case rather than a dropdown that
				// cannot reach the value the seller needs.
				'truncated' => count( $offered ) > self::MAX_ASPECT_CHOICES,
				'value'     => $value,
				'source'    => $origin[0],
				'from'      => $origin[1],
			);

			if ( $row['required'] ) {
				$required[] = $row;
			} else {
				$optional[] = $row;
			}
		}

		// Specifics we would send that eBay does not list for this category. Not
		// an error, eBay accepts them - but worth showing, because a misspelled
		// attribute name turns up here instead of against the aspect it was meant
		// for, which is the whole explanation for a listing eBay keeps refusing.
		$extra   = array();
		$entered = array();
		foreach ( $provided as $key => $value ) {
			if ( '' === trim( $value ) || isset( $listed[ $key ] ) ) {
				continue;
			}
			$name           = isset( $display[ $key ] ) ? $display[ $key ] : $key;
			$extra[ $name ] = $value;

			// Reported separately when it was typed on this product, because those
			// are the only ones the merchant can edit - and a value that can be
			// stored but never shown in a box again is one that can never be
			// corrected or removed.
			if ( isset( $sources[ $key ] ) && 'entered' === $sources[ $key ][0] ) {
				$entered[ $name ] = $value;
			}
		}

		return array(
			'status'        => 'ok',
			'message'       => '',
			'category_id'   => $category_id,
			'aspects'       => array_merge( $required, $optional ),
			'extra'         => $extra,
			'entered_extra' => $entered,
		);
	}

	/**
	 * Reduce an aspect name to a comparable form.
	 *
	 * eBay's names are not typeable without ambiguity — "Circulated/Uncirculated"
	 * gets entered as "Circulated / Uncirculated" or "Circulated-Uncirculated"
	 * and the listing was then rejected for a specific the seller had plainly
	 * supplied. Comparing on letters and digits alone makes those equivalent.
	 *
	 * @param string $name Aspect or attribute name.
	 * @return string
	 */
	private static function normalize_aspect_name( $name ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
	}

	/**
	 * Fill in item specifics the plugin can work out for itself.
	 *
	 * eBay wants Circulated/Uncirculated as an item specific for most coin
	 * categories, but the plugin only ever sent the equivalent information as a
	 * ConditionDescriptor. The seller had already chosen the condition, so
	 * asking them to repeat it as a product attribute on every coin was busywork
	 * that also left the push blocked until they did.
	 *
	 * @param array $specifics Specifics gathered so far.
	 * @param array $settings  Resolved export settings.
	 * @return array
	 */
	private function add_derived_specifics( array $specifics, array $settings ) {
		if ( ! self::is_coins_category( $settings['category_id'] ?? '', $settings['item_type'] ?? '', $settings['category_name'] ?? '' ) ) {
			return $specifics;
		}

		$derived = self::derive_circulation( $settings );
		if ( '' === $derived ) {
			return $specifics;
		}

		// Anything the seller set explicitly wins over what we inferred.
		$target = self::normalize_aspect_name( 'Circulated/Uncirculated' );
		foreach ( array_keys( $specifics ) as $name ) {
			if ( self::normalize_aspect_name( $name ) === $target ) {
				return $specifics;
			}
		}

		$specifics['Circulated/Uncirculated'] = $derived;

		return $specifics;
	}

	/**
	 * Work out whether a coin is circulated from the condition already chosen.
	 *
	 * @param array $settings Resolved export settings.
	 * @return string "Uncirculated", "Circulated", or empty when undetermined.
	 */
	private static function derive_circulation( array $settings ) {
		$condition_type = $settings['condition_type'] ?? '';

		if ( 'ungraded' === $condition_type ) {
			$value = (string) ( $settings['ungraded_condition'] ?? '' );
			if ( '' === $value ) {
				return '';
			}

			// Only value 7 is Uncirculated. Value 8 reads "Extremely Fine to
			// About Uncirculated", which is a circulated grade despite the name.
			return ( '7' === $value ) ? 'Uncirculated' : 'Circulated';
		}

		if ( 'graded' === $condition_type ) {
			$grade = strtoupper( trim( (string) ( $settings['grade_value'] ?? '' ) ) );
			if ( '' === $grade ) {
				return '';
			}

			// Mint State, Proof and Specimen grades are uncirculated by
			// definition. Every other scale (AU, XF, VF, F, VG, G) grades wear.
			// The trailing class stands in for a word boundary: it has to match
			// "MS 65" and "MS/PR 69" while rejecting a longer word starting MS.
			return preg_match( '/^(MS|PR|PF|SP)([^A-Z]|$)/', $grade ) ? 'Uncirculated' : 'Circulated';
		}

		return '';
	}

	/**
	 * Rename specifics to the exact aspect names eBay publishes for the category.
	 *
	 * Applied to what is actually sent, not just to validation — passing our own
	 * pre-flight check and then being rejected by eBay for the same field would
	 * be worse than not checking at all.
	 *
	 * @param array $specifics Specifics to send.
	 * @param array $settings  Resolved export settings.
	 * @return array
	 */
	private function canonicalize_specific_names( array $specifics, array $settings ) {
		$category_id = $settings['category_id'] ?? '';
		if ( empty( $category_id ) || empty( $specifics ) ) {
			return $specifics;
		}

		$aspects = TCGiant_Sync_API::instance()->get_category_aspects( $category_id );
		if ( is_wp_error( $aspects ) || empty( $aspects ) ) {
			return $specifics;
		}

		$canonical = array();
		foreach ( $aspects as $aspect ) {
			if ( empty( $aspect['name'] ) ) {
				continue;
			}
			$canonical[ self::normalize_aspect_name( $aspect['name'] ) ] = $aspect['name'];
		}

		$out = array();
		foreach ( $specifics as $name => $value ) {
			$key = self::normalize_aspect_name( $name );
			$out[ isset( $canonical[ $key ] ) ? $canonical[ $key ] : $name ] = $value;
		}

		return $out;
	}

	/**
	 * Item specifics derived from the per-product grading/coin configuration.
	 *
	 * @param array $settings Merged export settings.
	 * @return array<string,string>
	 */
	private function collect_configured_specifics( array $settings ) {
		$item_type = $settings['item_type'] ?? '';

		// Only generate ItemSpecifics for Coins categories.
		if ( ! self::is_coins_category( $settings['category_id'] ?? '', $item_type, $settings['category_name'] ?? '' ) ) {
			return array();
		}

		$specifics = array();

		if ( 'graded' === ( $settings['condition_type'] ?? '' ) ) {
			// Certification — the grader name (e.g. "NGC", "PCGS").
			if ( ! empty( $settings['grader_id'] ) ) {
				$grader_name = self::get_grader_name_by_id( $settings['grader_id'], 'coins' );
				if ( $grader_name ) {
					$specifics['Certification'] = $grader_name;
				}
			}

			// Grade (e.g. "MS/PR 69").
			if ( ! empty( $settings['grade_value'] ) ) {
				$specifics['Grade'] = $settings['grade_value'];
			}

			// Certification Number.
			if ( ! empty( $settings['cert_number'] ) ) {
				$specifics['Certification Number'] = $settings['cert_number'];
			}
		} elseif ( 'ungraded' === ( $settings['condition_type'] ?? '' ) ) {
			// Ungraded coins should indicate they are not certified.
			$specifics['Certification'] = 'Uncertified';
		}

		// Year: required by eBay for all Coins & Paper Money categories.
		if ( ! empty( $settings['coin_year'] ) ) {
			$specifics['Year'] = $settings['coin_year'];
		}

		return $specifics;
	}

	/**
	 * Build <ItemSpecifics> XML for the listing.
	 *
	 * @param WC_Product $product  Product being listed.
	 * @param array      $settings Merged export settings.
	 * @return string XML, or '' when there is nothing to send.
	 */
	private function build_item_specifics_xml( WC_Product $product, array $settings ) {
		$specifics = $this->build_specifics_map( $product, $settings );

		if ( empty( $specifics ) ) {
			return '';
		}

		$xml = '<ItemSpecifics>' . "\n";
		foreach ( $this->specifics_sent( $specifics ) as $pair ) {
			$xml .= "\t<NameValueList>\n";
			$xml .= "\t\t<Name>" . esc_xml( $pair[0] ) . "</Name>\n";
			$xml .= "\t\t<Value>" . esc_xml( $pair[1] ) . "</Value>\n";
			$xml .= "\t</NameValueList>\n";
		}
		$xml .= '</ItemSpecifics>';

		return $xml;
	}

	/**
	 * The item specifics exactly as the item sends them: after eBay's cut to
	 * thirty, without the empty ones, each name and value cut to eBay's
	 * lengths. The template's specifics table is made from this same list, so
	 * the table and the specifics eBay shows beside it never disagree.
	 *
	 * @param array $specifics build_specifics_map()'s name => value.
	 * @return array[] Each array( name, value ).
	 */
	private function specifics_sent( array $specifics ) {
		// eBay caps the number of name/value pairs per listing.
		$specifics = array_slice( $specifics, 0, self::MAX_SPECIFICS, true );

		$sent = array();
		foreach ( $specifics as $name => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$sent[] = array(
				mb_substr( (string) $name, 0, self::MAX_SPECIFIC_NAME ),
				mb_substr( (string) $value, 0, self::MAX_SPECIFIC_VALUE ),
			);
		}

		return $sent;
	}

	/**
	 * A variable product's options, as VariationSpecificsSet sends them: each
	 * attribute's label, and its values by term name where it is a taxonomy.
	 *
	 * A list, one entry per attribute, rather than keyed by label: a global
	 * "Color" and a custom one share a label, and 3.27.7 sent both.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return array<int, array{0: string, 1: string[]}> Label and values.
	 */
	private function variation_specifics_set( WC_Product_Variable $product ) {
		$set = array();
		foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
			$label  = wc_attribute_label( $attr_name );
			$values = array();
			foreach ( $options as $opt ) {
				$term     = get_term_by( 'slug', $opt, $attr_name );
				$values[] = $term ? $term->name : $opt;
			}
			$set[] = array( $label, $values );
		}
		return $set;
	}

	/**
	 * Reverse-lookup a grader's display name from its eBay conditionDescriptorValueId.
	 *
	 * @param string $value_id The numeric value ID stored in settings.
	 * @param string $type     Either 'coins' or 'tcg'.
	 * @return string|false The grader display name, or false if not found.
	 */
	public static function get_grader_name_by_id( $value_id, $type = 'coins' ) {
		$map = 'coins' === $type ? TCGiant_Sync_Catalog::GRADERS_COINS : TCGiant_Sync_Catalog::GRADERS_TCG;

		foreach ( $map as $name => $id ) {
			if ( (string) $id === (string) $value_id ) {
				return $name;
			}
		}

		return false;
	}

	/**
	 * eBay's value ID for a trading card's grade (descriptor 27502).
	 *
	 * From the grade as the product or the settings hold it ("8", "9.5",
	 * "Authentic"), or a stored value that is one of eBay's IDs already, which
	 * goes as it is.
	 *
	 * @param string $grade Grade label, or eBay's ID for one.
	 * @return string The ID, or '' when the value is neither.
	 */
	public static function tcg_grade_id( $grade ) {
		$grade = trim( (string) $grade );

		if ( isset( TCGiant_Sync_Catalog::GRADE_IDS_TCG[ $grade ] ) ) {
			return TCGiant_Sync_Catalog::GRADE_IDS_TCG[ $grade ];
		}

		if ( in_array( $grade, TCGiant_Sync_Catalog::GRADE_IDS_TCG, true ) ) {
			return $grade;
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// ConditionDescriptor Methods
	// -------------------------------------------------------------------------

	/**
	 * Check if a category ID requires ConditionDescriptors.
	 *
	 * @param string $category_id eBay category ID.
	 * @return bool True if the category requires descriptors.
	 */
	public static function is_descriptor_category( $category_id, $item_type = '' ) {
		// "All Other" items never use ConditionDescriptors.
		if ( 'other' === $item_type ) {
			return false;
		}
		if ( in_array( $item_type, array( 'tcg', 'coins' ), true ) ) {
			return true;
		}
		return in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_TCG, true )
		    || in_array( (string) $category_id, self::DESCRIPTOR_CATEGORIES_COINS, true );
	}

	/**
	 * Check if a category is a Coins category (vs TCG).
	 *
	 * @param string $category_id eBay category ID.
	 * @param string $item_type Optional item type override.
	 * @return bool True if the category is a Coins category.
	 */
	/**
	 * Version in which coin categories began to be recognised by their path.
	 *
	 * Anything pushed before this, in a coin category, went to eBay without its
	 * grading descriptors, Certification, Grade, Year or Circulated/Uncirculated,
	 * because the category was not recognised as a coin category at all.
	 */
	const COIN_SPECIFICS_FIXED_IN = '3.5.6';

	/**
	 * Coin listings that were pushed before the fix and are still live.
	 *
	 * Reads stored meta only — no eBay calls — so it is cheap enough to check on
	 * an admin page load. The listings on eBay are wrong until they are pushed
	 * again; fixing the code did nothing for what had already been sent.
	 *
	 * @param int $limit Maximum number of products to return.
	 * @return int[] Product IDs.
	 */
	public static function find_coin_listings_needing_refresh( $limit = 500 ) {
		global $wpdb;

		// Pushed, or pushed before and now queued for a push, under way, or in
		// a push that did not finish - which is what a bulk refresh of these
		// very listings leaves them saying until each one is done. Only a push
		// that succeeded writes _ebay_export_last_pushed, or Link completing
		// one that did not finish. The banner counts only those still saying
		// pushed: the rest are on their way, or carry their own notice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} st ON p.ID = st.post_id AND st.meta_key = '_ebay_export_status'
			 LEFT JOIN {$wpdb->postmeta} lp ON p.ID = lp.post_id AND lp.meta_key = '_ebay_export_last_pushed'
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND ( st.meta_value = 'pushed'
			         OR ( st.meta_value IN ( 'queued', 'pushing', 'failed' ) AND lp.meta_value != '' ) )
			 LIMIT %d",
			(int) $limit
		) );

		if ( empty( $candidates ) ) {
			return array();
		}

		$global = get_option( 'tcgiant_sync_ebay_settings', array() );
		$stale  = array();

		foreach ( $candidates as $product_id ) {
			$product_id = (int) $product_id;

			// version_compare, not a string comparison: '3.5.10' sorts below
			// '3.5.6' lexically and would hide listings that are actually current.
			$pushed_with = (string) get_post_meta( $product_id, '_ebay_export_pushed_version', true );
			if ( '' !== $pushed_with && version_compare( $pushed_with, self::COIN_SPECIFICS_FIXED_IN, '>=' ) ) {
				continue;
			}

			$category_id = (string) get_post_meta( $product_id, '_ebay_export_category_id', true );
			if ( '' === $category_id ) {
				$category_id = (string) ( $global['export_category_id'] ?? '' );
			}

			$category_name = (string) get_post_meta( $product_id, '_ebay_export_category_name', true );
			if ( '' === $category_name ) {
				$category_name = (string) ( $global['export_category_name'] ?? '' );
			}

			$item_type = (string) get_post_meta( $product_id, '_ebay_export_item_type', true );

			if ( self::is_coins_category( $category_id, $item_type, $category_name ) ) {
				$stale[] = $product_id;
			}
		}

		return $stale;
	}

	public static function is_coins_category( $category_id, $item_type = '', $category_name = '' ) {
		$cat_str = (string) $category_id;

		// The actual eBay category is authoritative. If the category is in a
		// known list, use that — regardless of what item_type the user selected.
		// This prevents sending Coins descriptors to a TCG category (or vice versa).
		if ( in_array( $cat_str, self::DESCRIPTOR_CATEGORIES_COINS, true ) ) {
			return true;
		}
		if ( in_array( $cat_str, self::DESCRIPTOR_CATEGORIES_TCG, true ) ) {
			return false;
		}

		// Those lists hold eBay's top-level coin and card categories, but nobody
		// lists at the top level — a real coin goes in a leaf such as 526,
		// "Coins & Paper Money > Coins: US > Mint Sets". Every check above then
		// missed, and coin handling silently did not apply unless the seller had
		// also set Item Type on the product by hand.
		//
		// The category browser already records the full path it was chosen from,
		// so the answer is on the product. No extra eBay call needed.
		//
		// But not for a product set to All Other, as is_descriptor_category()
		// already has it. The path covers the whole of Coins & Paper Money, so
		// share certificates, bonds and banknotes counted as coins and were
		// sent the coin defaults - Certification, Grade, Year and
		// Circulated/Uncirculated - although the shop had said they were not.
		if ( 'other' !== $item_type && '' !== $category_name && false !== stripos( (string) $category_name, 'Coins & Paper Money' ) ) {
			return true;
		}

		// For custom/unknown categories, fall back to item_type.
		return 'coins' === $item_type;
	}

	/**
	 * Get the appropriate ungraded conditions for a category.
	 *
	 * @param string $category_id eBay category ID.
	 * @return array Value ID => label.
	 */
	public static function get_ungraded_conditions( $category_id, $item_type = '', $category_name = '' ) {
		if ( self::is_coins_category( $category_id, $item_type, $category_name ) ) {
			return TCGiant_Sync_Catalog::UNGRADED_COINS;
		}
		return TCGiant_Sync_Catalog::UNGRADED_TCG;
	}

	/**
	 * The products whose trading-card grade has no eBay ID and was said so in
	 * this push, by product ID: a relist builds its item twice, and says it
	 * once. Cleared with $pictures_for.
	 *
	 * @var array<int,bool>
	 */
	private $grade_said = array();

	/**
	 * Build <ConditionDescriptors> XML for the listing.
	 *
	 * Returns empty string if the category does not require descriptors
	 * or if the condition_type is not set.
	 *
	 * @param array $settings   Merged export settings.
	 * @param int   $product_id The product, for the log.
	 * @return string XML string or empty.
	 */
	private function build_condition_descriptors_xml( array $settings, $product_id = 0 ) {
		$item_type = $settings['item_type'] ?? '';
		if ( ! self::is_descriptor_category( $settings['category_id'], $item_type ) ) {
			return '';
		}

		if ( empty( $settings['condition_type'] ) ) {
			return '';
		}

		$xml = '<ConditionDescriptors>' . "\n";

		if ( 'graded' === $settings['condition_type'] ) {
			if ( self::is_coins_category( $settings['category_id'], $item_type, $settings['category_name'] ?? '' ) ) {
				// ---- COINS: Graded ----
				// Professional Grader (Descriptor Name 1) — value must be a numeric ID.
				if ( ! empty( $settings['grader_id'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>1</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['grader_id'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}

				// Grade (split into Letter [Descriptor 3] and Numeric [Descriptor 4])
				// User selects e.g. "MS/PR 65" — split into letter "MS/PR" and number "65",
				// then look up the eBay conditionDescriptorValueId for each.
				// Text aliases like "Gem BU (≈MS 65)" are resolved to their canonical
				// Sheldon-scale value before splitting.
				if ( ! empty( $settings['grade_value'] ) ) {
					$grade_raw = $settings['grade_value'];

					// Resolve text-based aliases to canonical Sheldon grades.
					if ( isset( self::TEXT_GRADE_ALIASES[ $grade_raw ] ) ) {
						$grade_raw = self::TEXT_GRADE_ALIASES[ $grade_raw ];
					}

					$parts = explode( ' ', $grade_raw, 2 );
					if ( count( $parts ) === 2 ) {
						$letter_label = $parts[0];
						$number_label = $parts[1];

						// Look up Letter Grade value ID.
						$letter_value_id = self::LETTER_GRADES_COINS[ $letter_label ] ?? '';
						if ( $letter_value_id ) {
							$xml .= "\t<ConditionDescriptor>\n";
							$xml .= "\t\t<Name>3</Name>\n";
							$xml .= "\t\t<Value>" . esc_attr( $letter_value_id ) . "</Value>\n";
							$xml .= "\t</ConditionDescriptor>\n";
						}

						// Look up Numeric Grade value ID.
						$numeric_value_id = self::NUMERIC_GRADES_COINS[ $number_label ] ?? '';
						if ( $numeric_value_id ) {
							$xml .= "\t<ConditionDescriptor>\n";
							$xml .= "\t\t<Name>4</Name>\n";
							$xml .= "\t\t<Value>" . esc_attr( $numeric_value_id ) . "</Value>\n";
							$xml .= "\t</ConditionDescriptor>\n";
						}
					}
				}

				// Certification Number (Descriptor Name 5) — free text, max 20 chars.
				if ( ! empty( $settings['cert_number'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>5</Name>\n";
					$xml .= "\t\t<AdditionalInfo>" . esc_attr( substr( $settings['cert_number'], 0, 20 ) ) . "</AdditionalInfo>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
			} else {
				// ---- TCG: Graded ----
				// Professional Grader (required) for TCG.
				if ( ! empty( $settings['grader_id'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>27501</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['grader_id'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
	
				// Grade (required) for TCG: eBay's value ID for it, never the grade
				// as it reads. "8" was refused: "Condition descriptor value 8 is not
				// valid for condition descriptor 27502". A grade with no ID is not
				// sent, and said once a push: eBay then asks for the grade, which
				// says more than a value it cannot read.
				if ( ! empty( $settings['grade_value'] ) ) {
					$grade_id = self::tcg_grade_id( $settings['grade_value'] );
					if ( '' !== $grade_id ) {
						$xml .= "\t<ConditionDescriptor>\n";
						$xml .= "\t\t<Name>27502</Name>\n";
						$xml .= "\t\t<Value>" . esc_attr( $grade_id ) . "</Value>\n";
						$xml .= "\t</ConditionDescriptor>\n";
					} elseif ( empty( $this->grade_said[ (int) $product_id ] ) ) {
						$this->grade_said[ (int) $product_id ] = true;
						TCGiant_Sync_Logger::warning( sprintf(
							'Push: the grade "%2$s" of WC #%1$d is not one eBay takes for a graded trading card, so it was not sent and eBay will ask for it. Choose the grade again in the product\'s eBay Listing tab.',
							(int) $product_id,
							$settings['grade_value']
						) );
					}
				}

				// Certification Number (optional) for TCG — free text, max 30 chars.
				if ( ! empty( $settings['cert_number'] ) ) {
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>27503</Name>\n";
					$xml .= "\t\t<AdditionalInfo>" . esc_attr( substr( $settings['cert_number'], 0, 30 ) ) . "</AdditionalInfo>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
			}
		} elseif ( 'ungraded' === $settings['condition_type'] ) {
			if ( ! empty( $settings['ungraded_condition'] ) ) {
				if ( self::is_coins_category( $settings['category_id'], $item_type, $settings['category_name'] ?? '' ) ) {
					// Coins: Descriptor Name 2 ("Coin Condition").
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>2</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['ungraded_condition'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				} else {
					// TCG: Descriptor Name 40001 ("Card Condition").
					$xml .= "\t<ConditionDescriptor>\n";
					$xml .= "\t\t<Name>40001</Name>\n";
					$xml .= "\t\t<Value>" . esc_attr( $settings['ungraded_condition'] ) . "</Value>\n";
					$xml .= "\t</ConditionDescriptor>\n";
				}
			}
		}

		$xml .= '</ConditionDescriptors>';

		return $xml;
	}

	// -------------------------------------------------------------------------
	// Helper Methods
	// -------------------------------------------------------------------------

	/**
	 * Package weight and size for the listing, when the shop has asked for it.
	 *
	 * Nothing was ever sent. A shop that set a weight in WooCommerce saw eBay
	 * reject the listing for having none, and there was no setting anywhere
	 * that would have changed it - it simply did not travel.
	 *
	 * OFF BY DEFAULT, deliberately. Every shop that has ever pushed did so
	 * without weights, and their listings work: flat-rate postage needs none.
	 * Switching this on for them would rewrite every live listing on the next
	 * bulk push. They can choose it; a shop on calculated postage will want to.
	 *
	 * ROUNDING IS TO NEAREST, NOT UP, AND THIS MATTERS. The importer writes
	 * weights back from eBay on every scheduled sync, so what goes out comes
	 * back in and goes out again. Rounding up looks safe - the shop, not the
	 * buyer, pays for an under-declared parcel - but it ratchets: a 3 oz card
	 * in a gram-unit shop went 3, 4, 5, 6, 7, 8 oz, one more each cycle, with
	 * no fixed point. Nearest rounding is stable in all four store units.
	 * Measured, not reasoned.
	 *
	 * A variable product has one package for every variation, so the heaviest
	 * published one is used - never the first, whose order is arbitrary.
	 *
	 * Known limit: a weight cannot be REMOVED from a live listing this way.
	 * Leaving the field blank sends nothing, and on a revise eBay keeps what it
	 * had. To change a wrong weight, set the right one.
	 *
	 * @param WC_Product $product  The product being listed.
	 * @param array      $settings Merged export settings.
	 * @return string XML, or empty when there is nothing to send.
	 */
	private function build_shipping_package_xml( WC_Product $product, array $settings ) {
		if ( empty( $settings['send_package_details'] ) ) {
			return '';
		}

		$source = $this->package_source_product( $product );
		$weight = (float) $source->get_weight();

		if ( $weight <= 0 ) {
			return '';
		}

		$system    = $this->export_measurement_system();
		$is_metric = ( 'Metric' === $system );
		$w_unit    = get_option( 'woocommerce_weight_unit', 'kg' );
		$d_unit    = get_option( 'woocommerce_dimension_unit', 'cm' );

		list( $major, $minor ) = self::weight_to_ebay( $weight, $w_unit, $system );

		// MeasurementUnit is the load-bearing field: it fixes what Major and Minor
		// mean. The unit attributes are for anyone reading the payload in a log.
		$xml  = '<ShippingPackageDetails>' . "\n";
		$xml .= "\t<MeasurementUnit>" . $system . "</MeasurementUnit>\n";
		$xml .= "\t<WeightMajor unit=\"" . ( $is_metric ? 'kg' : 'lbs' ) . "\">" . $major . "</WeightMajor>\n";
		$xml .= "\t<WeightMinor unit=\"" . ( $is_metric ? 'g' : 'oz' ) . "\">" . $minor . "</WeightMinor>\n";

		$dims = array(
			'PackageLength' => $source->get_length(),
			'PackageWidth'  => $source->get_width(),
			'PackageDepth'  => $source->get_height(),
		);

		foreach ( $dims as $tag => $raw ) {
			$value = self::dimension_to_ebay( (float) $raw, $d_unit, $system );
			if ( '' !== $value ) {
				$xml .= "\t<" . $tag . " unit=\"" . ( $is_metric ? 'cm' : 'in' ) . "\">" . $value . "</" . $tag . ">\n";
			}
		}

		$xml .= '</ShippingPackageDetails>' . "\n";

		return $xml;
	}

	/**
	 * The product whose weight and size describe the parcel.
	 *
	 * @param WC_Product $product
	 * @return WC_Product
	 */
	private function package_source_product( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return $product;
		}

		$heaviest = $product;
		$max      = (float) $product->get_weight();

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || 'publish' !== $child->get_status() ) {
				continue;
			}
			$w = (float) $child->get_weight();
			if ( $w > $max ) {
				$max      = $w;
				$heaviest = $child;
			}
		}

		return $heaviest;
	}

	/**
	 * Which system the listing site expects. Mirrors the importer's reading of
	 * a listing: the United States is English, everything else is Metric.
	 *
	 * @return string 'English' or 'Metric'.
	 */
	private function export_measurement_system() {
		$site = TCGiant_Sync_API::instance()->get_marketplace_config()['site'];
		return in_array( $site, array( 'US', 'eBayMotors' ), true ) ? 'English' : 'Metric';
	}

	/**
	 * A WooCommerce weight as eBay wants it: whole major units and whole minor.
	 *
	 * Everything goes through grams with exact constants, then to nearest whole
	 * minor unit, then split. A weight that is set but rounds to nothing is sent
	 * as one minor unit rather than as nothing at all.
	 *
	 * @param float  $weight Value in the store unit.
	 * @param string $unit   Store unit: kg, g, lbs or oz.
	 * @param string $system 'English' (lbs + oz) or 'Metric' (kg + g).
	 * @return int[] Major and minor.
	 */
	public static function weight_to_ebay( $weight, $unit, $system ) {
		$grams_per = array( 'kg' => 1000.0, 'g' => 1.0, 'lbs' => 453.59237, 'oz' => 28.349523125 );
		$grams     = (float) $weight * ( $grams_per[ $unit ] ?? 1000.0 );

		if ( 'Metric' === $system ) {
			$n         = (int) round( $grams );
			$per_major = 1000;
		} else {
			$n         = (int) round( $grams / 28.349523125 );
			$per_major = 16;
		}

		if ( $grams > 0 && $n < 1 ) {
			$n = 1;
		}

		return array( intdiv( $n, $per_major ), $n % $per_major );
	}

	/**
	 * A WooCommerce dimension in the unit the listing site expects.
	 *
	 * @param float  $value  Value in the store unit.
	 * @param string $unit   Store unit: mm, cm, m, in or yd.
	 * @param string $system 'English' (inches) or 'Metric' (centimetres).
	 * @return string Up to two decimals, or '' when there is nothing to send.
	 */
	public static function dimension_to_ebay( $value, $unit, $system ) {
		if ( $value <= 0 ) {
			return '';
		}

		$cm_per = array( 'mm' => 0.1, 'cm' => 1.0, 'm' => 100.0, 'in' => 2.54, 'yd' => 91.44 );
		$cm     = (float) $value * ( $cm_per[ $unit ] ?? 1.0 );
		$out    = round( ( 'Metric' === $system ) ? $cm : $cm / 2.54, 2 );

		if ( $out <= 0 ) {
			$out = 0.01;
		}

		return rtrim( rtrim( number_format( $out, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Sanitize a product title to eBay's 80-character limit.
	 *
	 * @param string $title Raw WooCommerce product name.
	 * @return string Sanitized, truncated title.
	 */
	private function sanitize_title( $title ) {
		// Angle brackets only.
		//
		// This stripped < > & " ' ! @ # and * on the grounds that eBay
		// disallows them. eBay does not: they are ordinary characters in a
		// title, and the ones that matter to XML are already handled where the
		// title is placed in the payload, which runs it through esc_xml().
		// So they were being removed twice over, and the second removal was
		// destructive rather than protective.
		//
		// The cost fell hardest on exactly the sellers this was written for. A
		// card number is written "Base Set #4", and stripping the hash left
		// "Base Set 4". Apostrophes and ampersands went the same way out of
		// brand names, and asterisks out of part numbers.
		//
		// Angle brackets stay out. Nothing legitimate in a product title has
		// them, and eBay treats markup in a title as a policy matter rather
		// than escaping it away.
		$title = preg_replace( '/[<>]/', '', $title );
		$title = trim( $title );

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Export: Title truncated from %d to %d chars: "%s..."',
				mb_strlen( $title ),
				self::MAX_TITLE_LENGTH,
				mb_substr( $title, 0, 40 )
			), 'warning' );
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		return $title;
	}

	/**
	 * Build the HTML description for an eBay listing.
	 *
	 * Falls back to a simple formatted block if no WC description exists.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return string HTML description string.
	 */
	private function build_description( WC_Product $product ) {
		$desc = $product->get_description();

		if ( empty( trim( $desc ) ) ) {
			$desc = $product->get_short_description();
		}

		if ( empty( trim( $desc ) ) ) {
			// Auto-generate a minimal description from product attributes.
			$attrs = $product->get_attributes();
			if ( ! empty( $attrs ) ) {
				$lines = array();
				foreach ( $attrs as $attr ) {
					$name  = wc_attribute_label( $attr->get_name() );
					$value = implode( ', ', $attr->get_options() );
					if ( $name && $value ) {
						$lines[] = '<strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $value );
					}
				}
				if ( ! empty( $lines ) ) {
					$desc = implode( '<br>', $lines );
				}
			}
		}

		if ( empty( trim( $desc ) ) ) {
			$desc = esc_html( $product->get_name() );
		}

		// Apply WP content filters (shortcodes, etc.) for a clean HTML output.
		return apply_filters( 'the_content', $desc );
	}

	/**
	 * The description a listing is sent with.
	 *
	 * With no template - None as the default, a product set to None, a
	 * template since deleted - exactly build_description(), as every release
	 * before templates sent it, unless the product has an eBay-only
	 * description: that goes through the_content and then the sanitiser,
	 * template or not, since it is new and there is no old output to keep.
	 *
	 * A product linked to a listing on another eBay site is sent the plain
	 * description whatever its rules say, and the log says why: this shop's
	 * template, in this shop's language, must never reach an eBay.de or
	 * eBay.co.uk copy.
	 *
	 * Push, Verify and the template preview all come here, so the preview shows
	 * the bytes a push sends.
	 *
	 * @param WC_Product  $product  Product being listed.
	 * @param array       $settings get_export_settings(): template_id (or the
	 *                              editor's template_draft), and the policies the
	 *                              item attaches.
	 * @param string|null $title    The title the item sends; worked out here when
	 *                              not given.
	 * @return array html, none, template_id, version, hash, tpl_hash,
	 *               content_hash, chars, warnings, removed.
	 */
	public function build_listing_description( WC_Product $product, array $settings, $title = null ) {
		$templates = class_exists( 'TCGiant_Sync_Templates' );
		$draft     = $templates && isset( $settings['template_draft'] ) && is_array( $settings['template_draft'] );
		$tpl_id    = $templates ? (int) ( $settings['template_id'] ?? 0 ) : 0;
		$ebay_only = $templates ? trim( (string) $product->get_meta( TCGiant_Sync_Templates::EBAY_DESCRIPTION_META ) ) : '';
		$warnings  = array();

		if ( $tpl_id > 0 || $draft || '' !== $ebay_only ) {
			$site = (string) $product->get_meta( '_ebay_site' );
			$here = TCGiant_Sync_API::instance()->get_marketplace_config();
			if ( '' !== $site && TCGiant_Sync_Sites::marketplace_of( $site ) !== $here['marketplace_id'] ) {
				$tpl_id    = 0;
				$draft     = false;
				$ebay_only = '';
				$warnings[] = sprintf(
					/* translators: %s: an eBay site, e.g. "eBay Germany" */
					__( 'This product\'s eBay listing is on %s, not your Primary eBay Marketplace, so it is sent the plain description: no template and no eBay-only description.', 'tcgiant-sync' ),
					TCGiant_Sync_Sites::label( $site )
				);
				if ( empty( $settings['_preview'] ) ) {
					TCGiant_Sync_Logger::log( sprintf(
						'Listing template not used for WC #%1$d: its eBay listing is on %2$s, not the shop\'s %3$s, so it was sent the plain description.',
						$product->get_id(),
						TCGiant_Sync_Sites::label( $site ),
						'eBay ' . ( TCGiant_Sync_API::MARKETPLACES[ $here['marketplace_id'] ]['label'] ?? $here['marketplace_id'] )
					), 'info' );
				}
			}
		}

		$render = array();
		if ( $tpl_id > 0 || $draft ) {
			if ( null === $title ) {
				$title = $this->sanitize_title( $product->get_name() );
			}
			$render = TCGiant_Sync_Templates::render( $product, $settings, $this->template_facts( $product, $settings, $title, $ebay_only ) );
		}

		if ( empty( $render ) ) {
			$removed = array();
			if ( '' !== $ebay_only ) {
				$clean    = TCGiant_Sync_Template_Sanitiser::clean( (string) apply_filters( 'the_content', $ebay_only ), array(
					'home_host'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'site_https' => TCGiant_Sync_Templates::site_https(),
				) );
				$html     = $clean['html'];
				$removed  = TCGiant_Sync_Templates::count_removed( $clean['removed'] );
				$warnings = array_merge( $warnings, $clean['warnings'], TCGiant_Sync_Template_Sanitiser::lint( $html ) );
			} else {
				$html = $this->build_description( $product );
			}

			$render = array(
				'html'         => $html,
				'none'         => true,
				// 3.27.7's own description, built as it built it.
				'plain'        => '' === $ebay_only,
				'template_id'  => 0,
				'version'      => 0,
				'name'         => '',
				'tpl_hash'     => '',
				'content_hash' => sha1( (string) $html ),
				'region_hash'  => '',
				'warnings'     => $warnings,
				'removed'      => $removed,
			);
		} elseif ( $warnings ) {
			$render['warnings'] = array_merge( $warnings, $render['warnings'] );
		}

		/**
		 * Filters the description a listing is sent with.
		 *
		 * With no callbacks it changes nothing: the plain description stays
		 * byte for byte what it was.
		 *
		 * @since 3.27.8
		 *
		 * @param string     $html    The description.
		 * @param WC_Product $product The product being listed.
		 * @param array      $render  What built it: template_id, version, none.
		 */
		$render['html']  = (string) apply_filters( 'tcgiant_sync_ebay_description', $render['html'], $product, $render );
		$render['hash']  = sha1( $render['html'] );
		$render['chars'] = function_exists( 'mb_strlen' ) ? mb_strlen( $render['html'], 'UTF-8' ) : strlen( $render['html'] );

		return $render;
	}

	/**
	 * Why the description the last build put in its item cannot be sent, if
	 * it cannot: eBay's 500,000-character ceiling.
	 *
	 * Only for a description a template or the eBay-only description made.
	 * The plain description is sent exactly as 3.27.7 sent it, and eBay
	 * decides, so a shop that picks no template sees no change.
	 *
	 * @return WP_Error|null
	 */
	public function description_too_long() {
		$chars = (int) ( $this->last_render['chars'] ?? 0 );
		if ( $chars <= 500000 || ! empty( $this->last_render['plain'] ) ) {
			return null;
		}
		return new WP_Error(
			'description_too_long',
			sprintf(
				/* translators: 1: characters, 2: the limit */
				__( 'This product\'s eBay description is %1$s characters long, and eBay takes up to %2$s, tags included. Shorten the description, or choose a smaller listing template, then push again. Nothing was sent to eBay.', 'tcgiant-sync' ),
				number_format_i18n( $chars ),
				number_format_i18n( 500000 )
			)
		);
	}

	/**
	 * The description the last build_item_xml() put in its item.
	 *
	 * @return array|null
	 */
	public function last_render() {
		return $this->last_render;
	}

	/**
	 * Record on a product the description eBay has just accepted.
	 *
	 * @param WC_Product $product Product, saved by the caller.
	 * @param array|null $desc    build_listing_description()'s answer.
	 * @param string     $item_id The listing it was sent to.
	 */
	private static function record_description_sent( WC_Product $product, $desc, $item_id = '' ) {
		if ( ! is_array( $desc ) || ! isset( $desc['hash'] ) ) {
			return;
		}
		// As strings, which is how post meta comes back.
		$product->update_meta_data( '_tcgiant_tpl_id', (string) (int) ( $desc['template_id'] ?? 0 ) );
		$product->update_meta_data( '_tcgiant_tpl_ver', (string) (int) ( $desc['version'] ?? 0 ) );
		$product->update_meta_data( '_tcgiant_tpl_hash_sent', (string) ( $desc['tpl_hash'] ?? '' ) );
		$product->update_meta_data( '_tcgiant_content_hash', (string) ( $desc['content_hash'] ?? '' ) );
		$product->update_meta_data( '_tcgiant_desc_hash', (string) $desc['hash'] );
		$product->update_meta_data( '_tcgiant_region_hash', (string) ( $desc['region_hash'] ?? '' ) );
		// Which listing: a re-import cuts a content region only for the
		// listing the record is of (TCGiant_Sync_Mapper::sent_with_template()).
		$product->update_meta_data( '_tcgiant_tpl_item', (string) $item_id );
		$product->update_meta_data( '_tcgiant_desc_sent_at', (string) time() );
	}

	/**
	 * What a template may say about a product, taken from what the item sends.
	 *
	 * @param WC_Product $product   Product being listed.
	 * @param array      $settings  Export settings.
	 * @param string     $title     The title the item sends.
	 * @param string     $ebay_only The eBay-only description, or ''.
	 * @return array
	 */
	private function template_facts( WC_Product $product, array $settings, $title, $ebay_only ) {
		$attributes = $this->attribute_rows( $product );

		$by_slug = array();
		foreach ( $attributes as $row ) {
			$by_slug[ $row['slug'] ] = $row['value'];
			// {{attr.brand}} as well as {{attr.pa_brand}} for a global attribute.
			if ( 0 === strpos( $row['slug'], 'pa_' ) && ! isset( $by_slug[ substr( $row['slug'], 3 ) ] ) ) {
				$by_slug[ substr( $row['slug'], 3 ) ] = $row['value'];
			}
		}

		return array(
			'title'             => (string) $title,
			'content_source'    => '' !== $ebay_only ? $ebay_only : $this->content_source( $product, $attributes ),
			'short_description' => (string) $product->get_short_description(),
			'sku'               => (string) $product->get_sku(),
			'attributes'        => $by_slug,
			'condition_name'    => $this->condition_name( $settings ),
			'grading'           => $this->grading_facts( $settings ),
			'specifics'         => $this->specifics_sent( $this->build_specifics_map( $product, $settings ) ),
			'variations'        => $product instanceof WC_Product_Variable ? $this->variation_specifics_set( $product ) : array(),
			'images'            => $this->description_image_urls( $product ),
		);
	}

	/**
	 * The website description, by build_description()'s fallback chain - long,
	 * short, attributes, name - but with attributes by their term names.
	 * build_description() lists a global attribute's term IDs, which the None
	 * path keeps so that it stays byte for byte what it was.
	 *
	 * @param WC_Product $product    Product.
	 * @param array      $attributes attribute_rows().
	 * @return string
	 */
	private function content_source( WC_Product $product, array $attributes ) {
		$desc = (string) $product->get_description();

		if ( '' === trim( $desc ) ) {
			$desc = (string) $product->get_short_description();
		}

		if ( '' === trim( $desc ) ) {
			$lines = array();
			foreach ( $attributes as $row ) {
				if ( '' !== $row['label'] && '' !== $row['value'] ) {
					$lines[] = '<strong>' . esc_html( $row['label'] ) . ':</strong> ' . esc_html( $row['value'] );
				}
			}
			$desc = implode( '<br>', $lines );
		}

		if ( '' === trim( $desc ) ) {
			$desc = esc_html( $product->get_name() );
		}

		return $desc;
	}

	/**
	 * Every attribute of the product - variation axes too - with its values by
	 * name, as build_specifics_map() reads them.
	 *
	 * @param WC_Product $product Product.
	 * @return array[] slug, label, value.
	 */
	private function attribute_rows( WC_Product $product ) {
		$rows = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}
			$values = array_filter( array_map( 'trim', array_map( 'strval', (array) $values ) ), function ( $value ) {
				return '' !== $value;
			} );

			$rows[] = array(
				'slug'  => sanitize_title( $attribute->get_name() ),
				'label' => (string) wc_attribute_label( $attribute->get_name(), $product ),
				'value' => implode( ', ', $values ),
			);
		}
		return $rows;
	}

	/**
	 * eBay's name for the condition the item sends.
	 *
	 * In a card or coin category with a condition type, build_item_xml() sends
	 * ConditionID 2750 (graded) or 4000 (ungraded) with descriptors; otherwise
	 * the condition chosen, named from the catalogue.
	 *
	 * @param array $settings Export settings.
	 * @return string
	 */
	private function condition_name( array $settings ) {
		$item_type = $settings['item_type'] ?? '';
		if ( self::is_descriptor_category( $settings['category_id'] ?? '', $item_type ) && ! empty( $settings['condition_type'] ) ) {
			if ( 'graded' === $settings['condition_type'] ) {
				return __( 'Graded', 'tcgiant-sync' );
			}
			$ungraded = self::get_ungraded_conditions( $settings['category_id'] ?? '', $item_type, $settings['category_name'] ?? '' );
			$value    = (string) ( $settings['ungraded_condition'] ?? '' );
			return isset( $ungraded[ $value ] ) ? __( 'Ungraded', 'tcgiant-sync' ) . ' - ' . $ungraded[ $value ] : __( 'Ungraded', 'tcgiant-sync' );
		}

		return (string) ( TCGiant_Sync_Catalog::CONDITIONS[ (string) ( $settings['condition_id'] ?? '' ) ] ?? '' );
	}

	/**
	 * The grading line, for a graded card or coin only: the same grader, grade
	 * and certificate number as the ConditionDescriptors.
	 *
	 * @param array $settings Export settings.
	 * @return array|false grader, grade, cert.
	 */
	private function grading_facts( array $settings ) {
		$item_type = $settings['item_type'] ?? '';
		if ( ! self::is_descriptor_category( $settings['category_id'] ?? '', $item_type ) || 'graded' !== ( $settings['condition_type'] ?? '' ) ) {
			return false;
		}

		$coins  = self::is_coins_category( $settings['category_id'] ?? '', $item_type, $settings['category_name'] ?? '' );
		$grader = ! empty( $settings['grader_id'] ) ? self::get_grader_name_by_id( $settings['grader_id'], $coins ? 'coins' : 'tcg' ) : '';
		$cert   = (string) ( $settings['cert_number'] ?? '' );

		$grading = array(
			'grader' => false !== $grader ? (string) $grader : '',
			'grade'  => (string) ( $settings['grade_value'] ?? '' ),
			// The first 20 characters for coins, 30 for cards: the descriptors' limits.
			'cert'   => substr( $cert, 0, $coins ? 20 : 30 ),
		);

		return '' === implode( '', $grading ) ? false : $grading;
	}

	/**
	 * Image addresses for the description: the site's own https attachment
	 * URLs, never eBay-hosted ones. Pictures eBay hosts expire when unused, and
	 * the listing's photos are shown above the description by eBay itself.
	 *
	 * @param WC_Product $product Product.
	 * @return string[]
	 */
	private function description_image_urls( WC_Product $product ) {
		$urls = array();
		foreach ( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', (array) $product->get_gallery_image_ids() ) ) as $id ) {
			if ( $id <= 0 || count( $urls ) >= TCGiant_Sync_Templates::MAX_IMAGE_N ) {
				continue;
			}
			$url = TCGiant_Sync_Templates::image_url( $id );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * How long an uploaded eBay Picture Services URL stays valid for reuse.
	 *
	 * eBay keeps unreferenced pictures for a limited period, so the cache is
	 * refreshed well inside that window rather than kept indefinitely.
	 */
	const EPS_URL_TTL = 20 * DAY_IN_SECONDS;

	/**
	 * The photos each product's item was given in this push, by product ID:
	 * 'urls', the addresses it sends; 'left_out', the photos left out of them
	 * because the listing's others are on eBay (attachment ID => why);
	 * 'past_cap', how many of the product's photos are not sent for want of
	 * room; and 'too_long', whether that room ran out by the addresses'
	 * length together (MAX_PICTURE_CHARS) rather than by their number.
	 *
	 * A relist builds its item twice in one push, and the second build sends
	 * what the first decided: nothing is uploaded again, or said again.
	 * Cleared at the start of every push, and of every build for Verify.
	 *
	 * @var array<int,array{urls:string[],left_out:array<int,string>,past_cap:int,too_long:bool}>
	 */
	private $pictures_for = array();

	/**
	 * How long a listing's photo addresses may be together, as sent.
	 *
	 * eBay refuses a listing whose PictureURLs come to more than about 3975
	 * characters in all (PictureDetailsType; not verified here, and a seller
	 * reports about 4000). Twelve addresses never reached it; 24 long ones can
	 * - a CDN's, say, with the long names imported photos are given - so a
	 * listing stops short of it, with room to spare.
	 */
	const MAX_PICTURE_CHARS = 3900;

	/**
	 * The photos the last build held back from a revise, attachment ID => why.
	 *
	 * When any would be left out, a revise is sent no PictureDetails at all
	 * (build_item_xml()), and do_push() says so once eBay has answered.
	 * Empty when the build sent its photos as decided.
	 *
	 * @var array<int,string>
	 */
	private $pictures_held = array();

	/**
	 * Why a photo has no eBay copy when there is no file here to upload.
	 */
	const PICTURE_NOT_HERE = 'its file is not on this server';

	/**
	 * Whether a picture address is eBay's own (EPS, ebayimg.com): an imported
	 * photo kept on eBay by "Keep eBay-hosted URLs", or a copy uploaded here.
	 *
	 * @param string $url Picture address.
	 * @return bool
	 */
	private static function is_ebay_picture( $url ) {
		return 1 === preg_match( '#^https?://(?:[a-z0-9-]+\.)*ebayimg\.com(?::[0-9]+)?(?:[/?\#]|$)#i', (string) $url );
	}

	/**
	 * An attachment's eBay-hosted (EPS) copy: the cached one while it is
	 * fresh, otherwise uploaded now and cached.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $reason        Set, in plain words, when there is none.
	 * @return string The EPS URL, or '' when the photo could not be put on eBay.
	 */
	private function eps_picture_url( $attachment_id, &$reason ) {
		$reason = '';

		// Reuse a previous upload while it is still fresh, keyed to the file so
		// replacing the image invalidates it.
		$file       = get_attached_file( $attachment_id );
		$stamp      = $file && file_exists( $file ) ? (string) filemtime( $file ) : '0';
		$cached     = get_post_meta( $attachment_id, '_tcgiant_eps_url', true );
		$cached_key = get_post_meta( $attachment_id, '_tcgiant_eps_key', true );
		$cached_at  = (int) get_post_meta( $attachment_id, '_tcgiant_eps_time', true );

		if ( $cached && $cached_key === $stamp && ( time() - $cached_at ) < self::EPS_URL_TTL ) {
			return (string) $cached;
		}

		// Offloaded to a CDN, or deleted: nothing here to upload.
		if ( ! $file || ! file_exists( $file ) ) {
			$reason = self::PICTURE_NOT_HERE;
			return '';
		}

		$uploaded = TCGiant_Sync_API::instance()->upload_picture( $file, get_the_title( $attachment_id ) );

		if ( is_wp_error( $uploaded ) ) {
			$reason = rtrim( $uploaded->get_error_message(), '. ' );
			return '';
		}

		update_post_meta( $attachment_id, '_tcgiant_eps_url', $uploaded );
		update_post_meta( $attachment_id, '_tcgiant_eps_key', $stamp );
		update_post_meta( $attachment_id, '_tcgiant_eps_time', time() );

		return $uploaded;
	}

	/**
	 * The photos a listing sends: the featured image, then the gallery, up to
	 * MAX_IMAGES.
	 *
	 * eBay refuses a whole listing whose photos mix its own (EPS) addresses
	 * with addresses on another site ("A mixture of Self Hosted and EPS
	 * pictures are not allowed"), so a listing's photos are never a mix,
	 * whatever the settings:
	 *
	 * - No photo already on eBay, "Host listing images on eBay?" off: each
	 *   photo's own address, exactly as 3.27.7 sent them.
	 * - No photo already on eBay, the setting on: each goes as its eBay copy,
	 *   uploaded in order or taken from the cache. At the first that cannot be
	 *   put on eBay - its upload failed, or its file is not on this server -
	 *   nothing more is uploaded, and every photo goes as its own address, with
	 *   one warning.
	 * - A photo already on eBay, with any setting - an imported photo kept there
	 *   by "Keep eBay-hosted URLs", whose own address is eBay's: the listing
	 *   goes all on eBay. Each other photo goes as its eBay copy, uploaded or
	 *   from the cache, even with the setting off; one that cannot be put on
	 *   eBay is left out. A new listing goes without it, with one warning a
	 *   push naming each. A revise would take it off the live listing, so
	 *   build_item_xml() sends that one no PictureDetails at all, and the push
	 *   warns once eBay has answered (do_push()).
	 *
	 * A photo with no address at all is left out, as it always was. The copies
	 * uploaded stay cached for later pushes.
	 *
	 * Every item built for eBay gets its PictureDetails from here: AddItem and
	 * AddFixedPriceItem, the two Revise calls, a new listing for an ended one,
	 * and both Verify calls. RelistItem and RelistFixedPriceItem send no
	 * pictures; eBay keeps the listing's own.
	 *
	 * A product with more photos than a listing has room for - MAX_IMAGES of
	 * them, MAX_PICTURE_CHARS of their addresses together - sends the first
	 * that fit, with one warning a push saying how many were not sent, and why.
	 *
	 * Decided once a push (the memo), and said once: here, for an item that is
	 * sent its photos. A revise sent none says so once eBay has answered, and a
	 * new listing made for an ended one says the rest (do_push()).
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @param bool       $revise  Whether the item goes to a Revise call.
	 * @return string[] Array of image URLs.
	 */
	private function get_product_image_urls( WC_Product $product, $revise = false ) {
		// A relist's second build sends what the first decided.
		$product_id = $product->get_id();
		if ( isset( $this->pictures_for[ $product_id ] ) ) {
			return $this->pictures_for[ $product_id ]['urls'];
		}

		$left_out = array();
		$past_cap = 0;
		$urls     = $this->decide_product_image_urls( $product, $left_out, $past_cap );

		// The addresses' length together, as sent: the list stops before the
		// first that would take it past MAX_PICTURE_CHARS, and the rest are not
		// sent either. Website addresses can reach it; eBay's are short.
		$too_long = false;
		$chars    = 0;
		foreach ( $urls as $i => $url ) {
			$chars += strlen( esc_url( $url ) );
			if ( $chars > self::MAX_PICTURE_CHARS ) {
				$past_cap += count( $urls ) - $i;
				$urls      = array_slice( $urls, 0, $i );
				$too_long  = true;
				break;
			}
		}

		$this->pictures_for[ $product_id ] = array( 'urls' => $urls, 'left_out' => $left_out, 'past_cap' => $past_cap, 'too_long' => $too_long );

		if ( ! $revise || ! $left_out ) {
			if ( $left_out ) {
				TCGiant_Sync_Logger::warning( self::left_out_warning( $product_id, $left_out ) );
			}
			$this->say_pictures_not_sent( $product_id );
		}

		return $urls;
	}

	/**
	 * Logs the warning for the photos this push's decision had no room for,
	 * when it had any: past MAX_IMAGES, or past MAX_PICTURE_CHARS together.
	 *
	 * @param int $product_id Product.
	 */
	private function say_pictures_not_sent( $product_id ) {
		$decided = $this->pictures_for[ (int) $product_id ] ?? null;
		if ( $decided && $decided['past_cap'] > 0 ) {
			TCGiant_Sync_Logger::warning( self::past_cap_warning( (int) $product_id, $decided['past_cap'], count( $decided['urls'] ), $decided['too_long'] ) );
		}
	}

	/**
	 * The decision get_product_image_urls() describes, made afresh.
	 *
	 * @param WC_Product        $product  The WooCommerce product.
	 * @param array<int,string> $left_out Set to the photos left out because the
	 *                                    listing's others are on eBay, ID => why.
	 * @param int               $past_cap Set to how many photos come after the
	 *                                    first MAX_IMAGES, not sent.
	 * @return string[]
	 */
	private function decide_product_image_urls( WC_Product $product, &$left_out, &$past_cap ) {
		$ids = array();

		// Featured image, then the gallery.
		$thumb_id = (int) $product->get_image_id();
		if ( $thumb_id ) {
			$ids[] = $thumb_id;
		}
		foreach ( $product->get_gallery_image_ids() as $img_id ) {
			$ids[] = (int) $img_id;
		}

		// Each photo's own address, as 3.27.7 sent them with the setting off.
		$site_past = 0;
		$site      = $this->site_picture_urls( $ids, $site_past );

		$on_ebay = false;
		foreach ( $site as $url ) {
			if ( self::is_ebay_picture( $url ) ) {
				$on_ebay = true;
				break;
			}
		}

		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( ! $on_ebay && empty( $settings['host_images_on_ebay'] ) ) {
			$past_cap = $site_past;
			return $site;
		}

		$product_id = $product->get_id();
		$urls       = array();
		$reason     = '';
		foreach ( $ids as $id ) {
			// Already on eBay: its own address is eBay's.
			$own = (string) wp_get_attachment_image_url( $id, 'full' );

			// Past eBay's limit: not sent, and counted for the warning.
			if ( count( $urls ) >= self::MAX_IMAGES ) {
				if ( '' !== $own ) {
					$past_cap++;
				}
				continue;
			}

			if ( self::is_ebay_picture( $own ) ) {
				$urls[] = $own;
				continue;
			}

			$eps = $this->eps_picture_url( $id, $reason );
			if ( '' !== $eps ) {
				$urls[] = $eps;
				continue;
			}

			// No copy on eBay and no address on the website either - an
			// attachment since deleted, say: there is nothing to send, so it is
			// left out, as it always was. When eBay refused its upload, the log
			// says so, as 3.27.7's did.
			if ( '' === $own ) {
				if ( self::PICTURE_NOT_HERE !== $reason ) {
					TCGiant_Sync_Logger::log( sprintf(
						'eBay picture upload failed for attachment #%d (%s). It has no address on this site either, so it is left out of the listing.',
						$id,
						$reason
					) );
				}
				continue;
			}

			// Other photos of this listing are on eBay already, so this one
			// cannot go as its own address beside them.
			if ( $on_ebay ) {
				$left_out[ $id ] = $reason;
				continue;
			}

			TCGiant_Sync_Logger::warning( sprintf(
				'Push: photo #%d of WC #%d could not be put on eBay (%s), so this listing\'s photos are all sent from your website. eBay does not accept a mix.',
				$id,
				$product_id,
				$reason
			) );

			$past_cap = $site_past;
			return $site;
		}

		return $urls;
	}

	/**
	 * The one warning a push gives when a product has more photos than a
	 * listing has room for.
	 *
	 * @param int  $product_id Product.
	 * @param int  $not_sent   How many were not sent.
	 * @param int  $sent       How many were.
	 * @param bool $too_long   Whether the addresses' length together, rather
	 *                         than their number, left no room.
	 * @return string
	 */
	private static function past_cap_warning( $product_id, $not_sent, $sent, $too_long ) {
		if ( $too_long ) {
			if ( 1 === (int) $not_sent ) {
				return sprintf(
					'Push: the photo addresses of WC #%1$d are too long together for eBay, which takes about 4,000 characters of them in one listing, so the one after the first %2$d was not sent.',
					$product_id,
					$sent
				);
			}

			return sprintf(
				'Push: the photo addresses of WC #%1$d are too long together for eBay, which takes about 4,000 characters of them in one listing, so the %3$d after the first %2$d were not sent.',
				$product_id,
				$sent,
				$not_sent
			);
		}

		if ( 1 === (int) $not_sent ) {
			return sprintf(
				'Push: WC #%1$d has more photos than the %2$d eBay takes in one listing, so the one after the first %2$d was not sent.',
				$product_id,
				self::MAX_IMAGES
			);
		}

		return sprintf(
			'Push: WC #%1$d has more photos than the %2$d eBay takes in one listing, so the %3$d after the first %2$d were not sent.',
			$product_id,
			self::MAX_IMAGES,
			$not_sent
		);
	}

	/**
	 * The one warning a push gives for the photos it left out of a listing
	 * whose other photos are on eBay.
	 *
	 * @param int                $product_id Product.
	 * @param array<int,string>  $left_out   Attachment ID => why.
	 * @return string
	 */
	private static function left_out_warning( $product_id, array $left_out ) {
		$names  = array();
		$detail = array();
		foreach ( $left_out as $id => $reason ) {
			$names[]  = '#' . $id;
			$detail[] = '#' . $id . ': ' . $reason;
		}

		if ( 1 === count( $names ) ) {
			return sprintf(
				'Push: photo %s of WC #%d was left out of the listing: it could not be put on eBay (%s), and the listing\'s other photos are on eBay, which does not accept a mix.',
				$names[0],
				$product_id,
				reset( $left_out )
			);
		}

		return sprintf(
			'Push: photos %s of WC #%d were left out of the listing: they could not be put on eBay (%s), and the listing\'s other photos are on eBay, which does not accept a mix.',
			implode( ', ', $names ),
			$product_id,
			implode( '; ', $detail )
		);
	}

	/**
	 * The one warning a push gives when its revise was sent no photos, so that
	 * none came off the live listing (build_item_xml()).
	 *
	 * It says when the listing's photos will change again. For an upload that
	 * failed, with the next push that can upload it. For a photo whose file is
	 * not on this server - an offloader moved it, say - no push can, so the
	 * listing's photos, the shop's own changes to them included, stay as they
	 * are until the file is back or the photo is taken off the product.
	 *
	 * @param int               $product_id Product.
	 * @param array<int,string> $held       Attachment ID => why.
	 * @return string
	 */
	private static function photos_unchanged_warning( $product_id, array $held ) {
		$names  = array();
		$detail = array();
		$absent = array();
		foreach ( $held as $id => $reason ) {
			$names[]  = '#' . $id;
			$detail[] = '#' . $id . ': ' . $reason;
			if ( self::PICTURE_NOT_HERE === $reason ) {
				$absent[] = '#' . $id;
			}
		}

		if ( ! $absent ) {
			$then = 1 === count( $names )
				? 'It goes on the listing with the next push that can upload it.'
				: 'They go on the listing with the next push that can upload them.';
		} elseif ( 1 === count( $absent ) ) {
			$then = sprintf( 'The listing\'s photos stay as they are until the file of %s is back on this server or that photo is taken off the product.', $absent[0] );
		} else {
			$then = sprintf( 'The listing\'s photos stay as they are until the files of %s are back on this server or those photos are taken off the product.', implode( ', ', $absent ) );
		}

		if ( 1 === count( $names ) ) {
			return sprintf(
				'Push: photo %s of WC #%d could not be put on eBay (%s), so the listing\'s photos were not changed this time: its other photos are on eBay, which does not accept a mix. %s',
				$names[0],
				$product_id,
				reset( $held ),
				$then
			);
		}

		return sprintf(
			'Push: photos %s of WC #%d could not be put on eBay (%s), so the listing\'s photos were not changed this time: its other photos are on eBay, which does not accept a mix. %s',
			implode( ', ', $names ),
			$product_id,
			implode( '; ', $detail ),
			$then
		);
	}

	/**
	 * The photos' own addresses, as a listing sends them with "Host listing
	 * images on eBay?" off and none of them on eBay.
	 *
	 * @param int[] $ids      Attachment IDs, the featured image first.
	 * @param int   $past_cap Set to how many with an address come after the
	 *                        first MAX_IMAGES, and so are not among them.
	 * @return string[]
	 */
	private function site_picture_urls( array $ids, &$past_cap = 0 ) {
		$urls     = array();
		$past_cap = 0;
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( ! $src ) {
				continue;
			}
			if ( count( $urls ) >= self::MAX_IMAGES ) {
				$past_cap++;
				continue;
			}
			$urls[] = $src;
		}
		return $urls;
	}

	/**
	 * Get cached business policies, or fetch fresh from eBay.
	 *
	 * Policies are stored permanently in wp_options. They only refresh when
	 * the user explicitly clicks "Fetch eBay Policies" (which calls clear_policy_cache()).
	 *
	 * @param string $type One of 'fulfillment', 'return', 'payment'.
	 * @return array|WP_Error Array of policy objects, or WP_Error on failure.
	 */
	public static function get_policies( $type ) {
		$option_key = 'tcgiant_export_policies_' . $type;
		$cached     = get_option( $option_key, false );

		if ( false !== $cached && is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$api = TCGiant_Sync_API::instance();

		switch ( $type ) {
			case 'fulfillment':
				$response = $api->get_fulfillment_policies();
				$key      = 'fulfillmentPolicies';
				$id_field = 'fulfillmentPolicyId';
				break;
			case 'return':
				$response = $api->get_return_policies();
				$key      = 'returnPolicies';
				$id_field = 'returnPolicyId';
				break;
			case 'payment':
				$response = $api->get_payment_policies();
				$key      = 'paymentPolicies';
				$id_field = 'paymentPolicyId';
				break;
			default:
				return new WP_Error( 'invalid_policy_type', 'Invalid policy type: ' . $type );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$policies = array();
		if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
			foreach ( $response[ $key ] as $policy ) {
				$row = array(
					'id'   => $policy[ $id_field ] ?? '',
					'name' => $policy['name'] ?? 'Unknown Policy',
				);

				// What a listing template's policy blocks say, kept from this same
				// answer, so they cost no call of their own and a description
				// never asks eBay anything (TCGiant_Sync_Template_Policies). The
				// field names are eBay's Account API v1 ReturnPolicy and
				// FulfillmentPolicy, checked against its specification:
				// https://developer.ebay.com/api-docs/master/sell/account/openapi/3/sell_account_v1_oas3.json
				if ( 'return' === $type ) {
					$row['returns_accepted']  = isset( $policy['returnsAccepted'] ) ? (bool) $policy['returnsAccepted'] : null;
					$row['return_period']     = self::time_duration( $policy['returnPeriod'] ?? null );
					$row['return_cost_payer'] = is_string( $policy['returnShippingCostPayer'] ?? null ) ? $policy['returnShippingCostPayer'] : '';
				} elseif ( 'fulfillment' === $type ) {
					$row['handling_time'] = self::time_duration( $policy['handlingTime'] ?? null );
				}

				$policies[] = $row;
			}
		}

		// Store permanently — only refreshed when user clicks "Fetch eBay Policies".
		update_option( $option_key, $policies, false );
		update_option( 'tcgiant_export_policies_fetched', time(), false );

		return $policies;
	}

	/**
	 * An Account API TimeDuration - {"value": 30, "unit": "DAY"} - as stored.
	 *
	 * @param mixed $duration From eBay's answer.
	 * @return array|null value and unit, or null when there is none.
	 */
	private static function time_duration( $duration ) {
		if ( ! is_array( $duration ) || ! isset( $duration['value'] ) || ! is_numeric( $duration['value'] ) ) {
			return null;
		}
		return array(
			'value' => (int) $duration['value'],
			'unit'  => is_string( $duration['unit'] ?? null ) ? strtoupper( $duration['unit'] ) : '',
		);
	}

	/**
	 * Bust the cached business policies (called after user clicks "Refresh Policies").
	 */
	public static function clear_policy_cache() {
		delete_option( 'tcgiant_export_policies_fulfillment' );
		delete_option( 'tcgiant_export_policies_return' );
		delete_option( 'tcgiant_export_policies_payment' );
	}

	// -------------------------------------------------------------------------
	// Completion Check
	// -------------------------------------------------------------------------

	/**
	 * A queue run is over: ask again whether the bulk push is.
	 *
	 * Every push asks as it ends, while Action Scheduler still counts it as
	 * running, along with any push running beside it in another queue
	 * runner. Two runners finishing the batch's last two pushes together
	 * each saw the other's still running, and the batch never ended. By the
	 * time a runner's queue run is over, the pushes it ran are marked done.
	 *
	 * Asked only while a batch is open, and then of the database, not of
	 * this request's copy: the other runner may have ended it since.
	 */
	public function recheck_export_completion() {
		if ( ! in_array( self::get_export_state()['status'] ?? '', array( 'scheduling', 'queued', 'pushing' ), true ) ) {
			return;
		}

		self::forget_cached_export_state();
		$state = self::get_export_state();

		// Still being scheduled. Long after it began, the request scheduling it
		// was stopped part way, and what it scheduled is all there will be:
		// the batch ends when those have run, and names the products it never
		// got to - still saying queued - as pushes that did not finish.
		if ( 'scheduling' === ( $state['status'] ?? '' ) ) {
			$began = strtotime( (string) ( $state['started_at'] ?? '' ) );
			if ( ! $began || strtotime( current_time( 'mysql' ) ) - $began <= self::PUSH_STALE_AFTER ) {
				return;
			}
			self::update_export_state( array( 'status' => 'queued' ) );
		}

		$this->check_export_completion( 0 );
	}

	/**
	 * Check if the bulk export is complete and update state accordingly.
	 *
	 * Every push that reports back - pushed, or refused by eBay - is counted,
	 * and the batch is over when they add up. When they do not, it is over
	 * once Action Scheduler has nothing left to run for it. That used to be
	 * the end of it too, and it was reported exactly like a clean batch:
	 * "Export complete!", with one added to the pushed figure "for the
	 * current item", which had already been counted. A clean batch of three
	 * said four, and a batch of three whose first push the host had killed
	 * said three, as if nothing had happened.
	 *
	 * Which pushes did not finish is read from the batch's products, not the
	 * figures. Each push reads the figures, adds one and writes them all
	 * back, so two queue runners at once - each with its own copy - write
	 * over each other's, and a batch that pushed every product said one had
	 * not finished. Each product's status is written by its own push alone.
	 *
	 * @param int $current_id The product whose push is calling, if one is.
	 *                        Action Scheduler still counts it as running.
	 */
	private function check_export_completion( $current_id = 0 ) {
		$state = self::get_export_state();

		// Not while the batch is still being scheduled: bulk_push_products()
		// asks itself once every push is.
		if ( ! in_array( $state['status'], array( 'queued', 'pushing' ), true ) ) {
			return;
		}

		// Kept by every batch since 3.27.4. One started before that has only
		// its figures to go by.
		$batch = isset( $state['product_ids'] ) && is_array( $state['product_ids'] ) ? array_map( 'intval', $state['product_ids'] ) : null;

		// Any push may end the batch, one of its own or not. A push from a
		// product's own screen, or left over from the batch before, is not
		// counted into it, but it may be the last push Action Scheduler runs:
		// the queue cleaner fails a batch's dead push five minutes on, and if
		// another push is waiting just then, ending the batch is left to that
		// one.

		// Written once, not by every push: each write is the whole state, from
		// this request's copy of it.
		if ( 'pushing' !== $state['status'] ) {
			self::update_export_state( array( 'status' => 'pushing' ) );
		}

		$queued = (int) $state['total_queued'];
		$pushed = (int) $state['total_pushed'];
		$errors = (int) $state['total_errors'];
		$asked  = false;

		if ( $pushed + $errors < $queued || $queued <= 0 ) {
			// Not every push has said how it went. Not over while one is still
			// waiting to run - or still running: a push the host killed goes
			// on looking like that until Action Scheduler gives up on it, and
			// calling the batch done before then would leave it out unsaid.
			if ( ! function_exists( 'as_get_scheduled_actions' ) || self::push_still_running( $current_id ) ) {
				return;
			}
			$asked = true;
		}

		if ( null === $batch ) {
			$names      = array();
			$unfinished = max( 0, $queued - $pushed - $errors );
		} else {
			$names = self::unfinished_in_batch( $batch );

			// The figures can add up with one of the batch's pushes still to
			// run: a product of the batch pushed again from its own screen
			// meanwhile is counted twice.
			if ( $names && ! $asked && ( ! function_exists( 'as_get_scheduled_actions' ) || self::push_still_running( $current_id ) ) ) {
				return;
			}

			$unfinished = count( $names );
			$pushed     = max( 0, $queued - $errors - $unfinished );
		}

		self::update_export_state( array(
			'status'         => $unfinished > 0 ? 'incomplete' : 'complete',
			'last_completed' => current_time( 'mysql' ),
			// What the products say, so the Export screen agrees with the line.
			'total_pushed'   => $pushed,
			// Nothing reads the batch's list once it is over, and the state is
			// an autoloaded option: up to a whole products page of IDs, loaded
			// on every request, and sent with every answer to the Export
			// screen, until the next bulk push.
			'product_ids'    => null,
		) );

		if ( 0 === $unfinished ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Export complete! %d pushed, %d errors out of %d total.',
				$pushed,
				$errors,
				$queued
			), 'success' );
			return;
		}

		$shown = array_slice( $names, 0, 20 );
		$which = $shown ? ' (WC #' . implode( ', #', $shown ) . ( count( $names ) > count( $shown ) ? sprintf( ' and %d more', count( $names ) - count( $shown ) ) : '' ) . ')' : '';

		TCGiant_Sync_Logger::warning( sprintf(
			'Export finished: %1$d of %2$d pushed, %3$d errors, %4$d did not finish%5$s. A push that did not finish may still have reached eBay: check eBay, and where the listing is there, use Link on the product with its item number - pushing it again would list it twice.',
			$pushed,
			$queued,
			$errors,
			$unfinished,
			$which
		) );
	}

	/**
	 * Whether Action Scheduler still has a push to run, or is running one,
	 * other than the one calling.
	 *
	 * @param int $current_id The product whose push is calling, or 0.
	 * @return bool
	 */
	private static function push_still_running( $current_id ) {
		$pending = as_get_scheduled_actions( array(
			'hook'     => self::PUSH_ACTION,
			'group'    => TCGiant_Sync_Importer::GROUP_SCAN,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		) );
		if ( ! empty( $pending ) ) {
			return true;
		}

		// Two are enough to know: the caller's own is one of them at most.
		$running = as_get_scheduled_actions( array(
			'hook'     => self::PUSH_ACTION,
			'group'    => TCGiant_Sync_Importer::GROUP_SCAN,
			'status'   => ActionScheduler_Store::STATUS_RUNNING,
			'per_page' => 2,
		) );
		foreach ( (array) $running as $action ) {
			$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? $action->get_args() : array();
			if ( (int) ( $args['product_id'] ?? 0 ) !== (int) $current_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The batch's products whose push did not finish: still queued, still
	 * saying they are pushing, or reported as not having finished.
	 *
	 * Read at the end of every batch, and in a request a push has just died
	 * in, so asked of the database in one query per 500 products rather than
	 * through get_post_meta(), which loads every meta row of each product -
	 * of a thousand products - to read one.
	 *
	 * @param int[] $batch The batch's products.
	 * @return int[] In the batch's order.
	 */
	private static function unfinished_in_batch( array $batch ) {
		global $wpdb;

		$batch = array_values( array_unique( array_filter( array_map( 'intval', $batch ) ) ) );
		$found = array();

		foreach ( array_chunk( $batch, 500 ) as $chunk ) {
			// Safe to interpolate: every id has been through intval().
			$in = implode( ',', $chunk );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_ebay_export_status'
				   AND meta_value IN ( 'queued', 'pushing', 'failed' )
				   AND post_id IN ( {$in} )"
			);

			foreach ( (array) $ids as $id ) {
				$found[ (int) $id ] = true;
			}
		}

		$names = array();
		foreach ( $batch as $product_id ) {
			if ( isset( $found[ $product_id ] ) ) {
				$names[] = $product_id;
			}
		}

		return $names;
	}
}
