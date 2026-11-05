<?php
/**
 * eBay Order Import
 *
 * Fetches eBay orders via Trading API's GetOrders and creates WooCommerce orders.
 *
 * New orders are found exactly as before 3.31.0: each run asks eBay for the
 * orders made in the last 24 hours (CreateTime), every page, and creates any
 * not here yet - so an order whose creation failed is tried again every run
 * for a day. Since 3.31.0 one nobody has paid for arrives as Pending payment,
 * not Processing, and the plugin's open orders - Pending payment, or with a
 * cancellation asked for - are followed by their OrderID until eBay says paid
 * (Processing) or cancelled (Cancelled), for up to 30 days. A sale eBay gives
 * a new OrderID - paid after it was made, or combined into one invoice - is
 * matched to the orders it was imported as by its items, not created again.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Orders class
 */
class TCGiant_Sync_Orders {

	/**
	 * Maximum order pages to walk in one import run.
	 */
	const MAX_ORDER_PAGES = 20;

	/**
	 * What an eBay order nobody has paid for yet arrives as, and goes back
	 * to (3.31.0): WooCommerce's "Pending payment". Until 3.31.0 it arrived
	 * as Processing, with the paid ones, and stayed there whatever eBay did
	 * next, so a shop could ship an order that was never paid for, or one
	 * the buyer had cancelled. 'on-hold' would keep it from being shipped
	 * too; 'processing' is what it was. Whichever, WooCommerce takes no stock
	 * for an eBay order - see TCGiant_Sync_Inventory::block_double_reduction().
	 */
	const UNPAID_STATUS = 'pending';

	/**
	 * The plugin's own open eBay orders, followed by their OrderID (3.31.0):
	 * eBay order ID => array( 'wc' => the order here, 'since', 'checked',
	 * 'items' => its line IDs ). An order is added when it is created, or
	 * read again, while eBay says Active, InProcess or CancelPending; it
	 * leaves once eBay says anything else (paid, cancelled), after
	 * FOLLOW_SECONDS, or when the order here is deleted. Never autoloaded.
	 *
	 * New orders are never found this way - only by the last day's
	 * CreateTime read, as before 3.31.0 - so nothing here can lose one.
	 */
	const FOLLOW_OPTION = 'tcgiant_sync_orders_follow';

	/**
	 * How long an open order is followed: eBay closes an unpaid order well
	 * within it.
	 */
	const FOLLOW_SECONDS = 2592000;

	/**
	 * Order IDs asked for in one GetOrders. eBay's reference gives no limit
	 * for OrderIDArray; this is GetOrders' largest page, so one page always
	 * holds the answer.
	 */
	const FOLLOW_PER_CALL = 100;

	/**
	 * GetOrders calls the follow-up makes a run at most: 200 orders, the
	 * least recently checked first. With the last day's read (at most
	 * MAX_ORDER_PAGES calls, as before 3.31.0) a run makes at most 22.
	 */
	const FOLLOW_CALLS_MAX = 2;

	/**
	 * Open orders followed at most; past it, the longest followed go first.
	 */
	const FOLLOW_KEEP_MAX = 2000;

	/**
	 * Refusals after which a followed order is dropped (fix round 4). A call
	 * eBay refuses (its Ack Failure) marks each order in it; a marked order
	 * is asked about again in a call of 10, then alone, so one ID eBay will
	 * not answer for is told from the orders that shared its call, and is
	 * dropped, logged, when it is refused on its own. An order answered for
	 * is unmarked.
	 *
	 * Fix round 5: at most one call a run asks about an order on its own (the
	 * other orders refused twice go on being asked ten to a call), and one
	 * refused on its own is dropped only when eBay answered another call of
	 * the same run. A refusal that has nothing to do with the IDs - every
	 * call refused, for an hour or a day - so drops nothing.
	 *
	 * Fix round 6: an order refused twice that is refused again, and was not
	 * the run's one order asked alone, sits the next run out ('rest'), so two
	 * IDs eBay always refuses in one call of ten cannot have both calls
	 * refused run after run and the whole follow-up held up.
	 */
	const FOLLOW_REFUSED_MAX = 3;

	/**
	 * The follow list for this run, while one is under way; null otherwise.
	 *
	 * @var array|null
	 */
	private $follow = null;

	/**
	 * The follow list's line IDs, for this run: line ID => the eBay order IDs
	 * followed by it. Added to, never cleaned: a key is checked against the
	 * list when it is used (fix round 4).
	 *
	 * @var array
	 */
	private $follow_lines = array();

	/**
	 * Instance.
	 *
	 * @var self|null
	 */
	private static $_instance = null;

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
	 * Constructor.
	 */
	public function __construct() {
		// Cron hook for auto-importing orders.
		add_action( 'tcgiant_sync_import_orders', array( $this, 'import_recent_orders' ) );

		// Order status change → push tracking to eBay.
		add_action( 'woocommerce_order_status_completed', array( $this, 'push_tracking_to_ebay' ) );

		// An eBay order is paid for on eBay, never on this website: one
		// waiting for payment is not offered for payment, or for the buyer
		// to cancel, in their account here (3.31.0).
		add_filter( 'woocommerce_order_needs_payment', array( $this, 'not_paid_here' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( $this, 'not_cancelled_here' ), 10, 2 );

		// Schedule order import cron if enabled.
		add_action( 'init', array( $this, 'schedule_cron' ) );
	}

	/**
	 * Schedule order import cron.
	 */
	public function schedule_cron() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( empty( $settings['enable_order_import'] ) ) {
			wp_clear_scheduled_hook( 'tcgiant_sync_import_orders' );
			return;
		}

		if ( ! wp_next_scheduled( 'tcgiant_sync_import_orders' ) ) {
			wp_schedule_event( time(), 'tcgiant_15mins', 'tcgiant_sync_import_orders' );
		}
	}

	/**
	 * Import recent eBay orders (last 24 hours), as before 3.31.0; bring
	 * those already imported up to date; and follow the plugin's open orders
	 * by their OrderID (3.31.0).
	 */
	public function import_recent_orders() {
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( empty( $settings['enable_order_import'] ) ) {
			return;
		}

		TCGiant_Sync_Logger::log( 'Order Import: Fetching recent eBay orders...' );

		$this->follow       = $this->follow_list();
		$this->follow_lines = array();
		foreach ( $this->follow as $key => $entry ) {
			$this->index_lines( (string) $key, (array) ( is_array( $entry ) ? ( $entry['items'] ?? array() ) : array() ) );
		}
		$following = $this->follow;
		$seen      = array();

		try {
			$orders = $this->fetch_orders();
			if ( is_wp_error( $orders ) ) {
				TCGiant_Sync_Logger::error( 'Order Import: Failed — ' . $orders->get_error_message() );
			} elseif ( empty( $orders ) ) {
				TCGiant_Sync_Logger::log( 'Order Import: No new orders found.' );
			} else {
				$this->import_orders( $orders, $seen );
			}

			// The open orders the last day's read did not bring.
			$this->follow_open_orders( $seen );
		} finally {
			$this->save_follow( $following );
			$this->follow       = null;
			$this->follow_lines = array();
		}
	}

	/**
	 * Create the orders not here yet, and bring those that are up to date.
	 *
	 * @param array $orders Orders from GetOrders.
	 * @param array $seen   Filled with the eBay order IDs read, as keys.
	 * @return void
	 */
	private function import_orders( array $orders, array &$seen ) {
		$created    = 0;
		$skipped    = 0;
		$updated    = 0;
		$paid_left  = 0;
		$renamed    = 0;
		$failed     = 0;
		$other_site = 0;

		foreach ( $orders as $ebay_order ) {
			$order_id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
			if ( '' !== $order_id ) {
				$seen[ $order_id ] = true;
			}

			// Deduplication: check if this eBay order was already imported -
			// and if so, bring it up to date with eBay (3.31.0).
			if ( $this->order_exists( $order_id ) ) {
				$done = $this->revisit_safely( $ebay_order );
				if ( 'error' === $done ) {
					$failed++;
				} elseif ( 'paid' === $done ) {
					$paid_left++;
				} elseif ( $done ) {
					$updated++;
				} else {
					$skipped++;
				}
				continue;
			}

			// The ID an order here had before eBay gave it a new one (fix
			// round 4, below): the same sale, already here.
			if ( $this->known_before( $order_id ) ) {
				$skipped++;
				continue;
			}

			// A sale eBay gave a new OrderID - paid after it was imported
			// unpaid (an auction, a Best Offer, Buy It Now without immediate
			// payment), or combined into a Combined Invoice: every line of it
			// is held by orders followed here, so it is not a new sale. The
			// first takes the new ID, and each is brought up to date; nothing
			// is created (fix round 4, Q12). Until 3.31.0 it was created again.
			$held = $this->followed_holding( $ebay_order );
			if ( ! empty( $held['all'] ) ) {
				$done = $this->take_new_id( $ebay_order, $held['all'] );
				if ( 'error' === $done ) {
					$failed++;
				} else {
					$renamed++;
					if ( 'paid' === $done ) {
						$paid_left++;
					}
				}
				continue;
			}
			if ( ! empty( $held['some'] ) ) {
				TCGiant_Sync_Logger::warning( sprintf(
					'Order Import: eBay order %s shares items with WC Order(s) #%s, imported before as eBay order(s) %s, but they do not hold all of it - created as a new order. Check the two for a duplicate.',
					$order_id,
					implode( ', #', array_map( function ( $order ) {
						return (int) $order->get_id();
					}, $held['some'] ) ),
					implode( ', ', array_keys( $held['some'] ) )
				) );
			}

			// An order for listings on other eBay sites only, when the shop
			// imports only its own (TCGiant_Sync_Sites): it would arrive in that
			// site's currency, as though it were the shop's. An order that also
			// holds a listing on the shop's own site is imported whole, since
			// half an order is worse than an order with a line the shop did not
			// expect.
			if ( $this->only_other_sites( $ebay_order ) ) {
				$other_site++;
				continue;
			}

			$wc_order = $this->create_wc_order( $ebay_order );
			if ( $wc_order ) {
				$created++;
			}
		}

		// Update last import timestamp - onto the settings as the database
		// holds them now. This request's copy is older than the import: a
		// token refreshed during it, or Disconnect pressed meanwhile in
		// another request, was put back as it was.
		TCGiant_Sync_Inventory::save_settings_keys( array( 'last_order_import' => current_time( 'mysql' ) ) );

		TCGiant_Sync_Logger::log( sprintf(
			'Order Import: Complete. %d created, %d skipped (already imported)%s%s%s%s%s.',
			$created, $skipped,
			$updated > 0 ? sprintf( ', %d brought up to date with eBay', $updated ) : '',
			$renamed > 0 ? sprintf( ', %d already here under the ID eBay gave them before (matched by their items)', $renamed ) : '',
			$paid_left > 0 ? sprintf( ', %d paid on eBay but left as set here (see the warnings)', $paid_left ) : '',
			$failed > 0 ? sprintf( ', %d not brought up to date (an error; each order\'s is logged once a day)', $failed ) : '',
			$other_site > 0 ? sprintf( ', %d left out (listed on other eBay sites)', $other_site ) : ''
		), 'success' );
	}

	/**
	 * Whether every line of an eBay order is a listing on another eBay site.
	 *
	 * Decided by each transaction's Item.Site - the site the listing is on - and
	 * never TransactionSiteID, the site the buyer used, which would leave out a
	 * buyer abroad who bought from the shop's own site. A line whose site eBay
	 * did not give is not another site's, so the order is imported.
	 *
	 * @param array $ebay_order Order from GetOrders.
	 * @return bool
	 */
	private function only_other_sites( $ebay_order ) {
		if ( ! TCGiant_Sync_Sites::primary_only() ) {
			return false;
		}

		$transactions = $this->get_transactions( $ebay_order );
		if ( empty( $transactions ) || ! is_array( $transactions ) ) {
			return false;
		}

		foreach ( $transactions as $transaction ) {
			$site = TCGiant_Sync_Sites::site_of( is_array( $transaction ) ? ( $transaction['Item'] ?? null ) : null );
			if ( ! TCGiant_Sync_Sites::leaves_out( $site ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Fetch orders from eBay via GetOrders Trading API.
	 *
	 * @return array|WP_Error Array of order data or error.
	 */
	private function fetch_orders() {
		$api = TCGiant_Sync_API::instance();

		// Fetch orders from the last 24 hours.
		$from = gmdate( 'Y-m-d\TH:i:s.000\Z', time() - DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d\TH:i:s.000\Z' );

		$orders      = array();
		$page        = 1;
		$total_pages = 1;

		// Walk every page. Requesting only page 1 meant any store taking more
		// than 100 orders in a day silently lost the remainder.
		do {
			$xml = '
<CreateTimeFrom>' . $from . '</CreateTimeFrom>
<CreateTimeTo>' . $to . '</CreateTimeTo>
<OrderRole>Seller</OrderRole>
<OrderStatus>All</OrderStatus>
<Pagination>
<EntriesPerPage>100</EntriesPerPage>
<PageNumber>' . (int) $page . '</PageNumber>
</Pagination>';

			$result = $api->trading_api_request( 'GetOrders', $xml );
			if ( is_wp_error( $result ) ) {
				// Return what we have if a later page fails; surface the error
				// only when nothing was retrieved at all.
				return empty( $orders ) ? $result : $orders;
			}

			if ( ! isset( $result['OrderArray']['Order'] ) ) {
				break;
			}

			$order_list = $result['OrderArray']['Order'];
			// Single order vs. multiple orders.
			if ( isset( $order_list['OrderID'] ) ) {
				$orders[] = $order_list;
			} else {
				$orders = array_merge( $orders, $order_list );
			}

			$total_pages = isset( $result['PaginationResult']['TotalNumberOfPages'] )
				? (int) $result['PaginationResult']['TotalNumberOfPages']
				: 1;

			$page++;
		} while ( $page <= $total_pages && $page <= self::MAX_ORDER_PAGES );

		if ( $total_pages > self::MAX_ORDER_PAGES ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Order Import: Stopped at the %d-page cap (%d pages available).',
				self::MAX_ORDER_PAGES, $total_pages
			) );
		}

		return $orders;
	}

	/**
	 * Read the plugin's open orders by their OrderID, and bring them up to
	 * date with eBay (3.31.0).
	 *
	 * Those the last day's read already brought this run are left out. Of
	 * the rest, at most FOLLOW_CALLS_MAX calls of FOLLOW_PER_CALL, the least
	 * recently checked first, so a long list is gone through over several
	 * runs. One deleted here is dropped without asking eBay. An order eBay
	 * says is paid or cancelled leaves the list (revisit_order()); one eBay
	 * does not return stays until FOLLOW_SECONDS.
	 *
	 * A call that does not reach eBay, or that eBay turns away for its
	 * allowance or the token, ends the follow-up for the run and keeps the
	 * list as it was. A call eBay refuses (fix round 4) marks its orders and
	 * the follow-up goes on to the next call: marked orders are asked about
	 * first, in calls of 10, then alone, and one refused on its own is
	 * dropped (FOLLOW_REFUSED_MAX), so one ID eBay will not answer for never
	 * holds up the rest.
	 *
	 * @param array $seen eBay order IDs the last day's read brought, as keys.
	 * @return void
	 */
	private function follow_open_orders( array $seen ) {
		if ( empty( $this->follow ) ) {
			return;
		}

		$now     = time();
		$expired = 0;
		$gone    = 0;
		foreach ( $this->follow as $id => $entry ) {
			if ( ! is_array( $entry ) || (int) ( $entry['since'] ?? 0 ) < $now - self::FOLLOW_SECONDS ) {
				unset( $this->follow[ $id ] );
				$expired++;
			}
		}

		$due = array_diff_key( $this->follow, $seen );
		uasort( $due, function ( $a, $b ) {
			return (int) ( $a['checked'] ?? 0 ) - (int) ( $b['checked'] ?? 0 );
		} );

		// The calls (fix round 5): one order eBay refused twice, asked on its
		// own - one call a run at most, so the other call shows whether eBay
		// answers at all; then the other orders it refused, ten to a call;
		// then the rest, FOLLOW_PER_CALL to a call. Each the least recently
		// asked first.
		$groups = array( array(), array(), array() );
		foreach ( $due as $id => $entry ) {
			$times = max( 0, (int) ( $entry['refused'] ?? 0 ) );
			if ( ! empty( $entry['rest'] ) ) {
				// Refused twice, and again last run but not alone: it sits
				// this run out, so the same refused call is not made again
				// and the other call can show eBay answering (fix round 6:
				// two IDs eBay always refuses in one call of ten held up
				// every followed order).
				unset( $this->follow[ $id ]['rest'] );
				continue;
			} elseif ( $times >= self::FOLLOW_REFUSED_MAX - 1 && ! $groups[0] ) {
				$groups[0][ $id ] = $entry;
			} elseif ( $times > 0 ) {
				$groups[1][ $id ] = $entry;
			} else {
				$groups[2][ $id ] = $entry;
			}
		}
		$solo  = $groups[0] ? (string) key( $groups[0] ) : null; // The order asked alone (fix round 6).
		$sizes = array( 1, max( 1, intdiv( self::FOLLOW_PER_CALL, 10 ) ), self::FOLLOW_PER_CALL );
		$plan  = array();
		foreach ( $groups as $g => $entries ) {
			$chunk = array();
			foreach ( $entries as $id => $entry ) {
				if ( count( $plan ) >= self::FOLLOW_CALLS_MAX ) {
					break 2;
				}
				$here = wc_get_order( (int) ( $entry['wc'] ?? 0 ) );
				if ( ! is_object( $here ) || ! method_exists( $here, 'get_status' ) || ! method_exists( $here, 'update_status' ) || 'trash' === $here->get_status() ) {
					unset( $this->follow[ $id ] );
					$gone++;
					continue;
				}
				$chunk[ (string) $id ] = $here;
				if ( count( $chunk ) >= $sizes[ $g ] ) {
					$plan[] = $chunk;
					$chunk  = array();
				}
			}
			if ( $chunk && count( $plan ) < self::FOLLOW_CALLS_MAX ) {
				$plan[] = $chunk;
			}
		}

		$api       = TCGiant_Sync_API::instance();
		$asked     = 0;
		$calls     = 0;
		$changed   = 0;
		$paid_left = 0;
		$failed    = 0;
		$closed    = 0;
		$refused   = 0;
		$dropped   = 0;
		$to_drop   = array();
		$answered  = false;

		foreach ( $plan as $chunk ) {
			$xml = '
<OrderIDArray>';
			foreach ( array_keys( $chunk ) as $id ) {
				$xml .= '
<OrderID>' . esc_attr( (string) $id ) . '</OrderID>';
			}
			$xml .= '
</OrderIDArray>
<OrderRole>Seller</OrderRole>
<Pagination>
<EntriesPerPage>' . (int) self::FOLLOW_PER_CALL . '</EntriesPerPage>
<PageNumber>1</PageNumber>
</Pagination>';

			$result = $api->trading_api_request( 'GetOrders', $xml );
			$calls++;
			$asked += count( $chunk );
			if ( is_wp_error( $result ) && 'api_error' !== $result->get_error_code() ) {
				TCGiant_Sync_Logger::warning( 'Order Import: the open orders could not be read by their OrderID — ' . $result->get_error_message() . '. They are asked about again next run.' );
				break;
			}
			if ( is_wp_error( $result ) ) {
				// eBay refused this call (fix round 4): its orders are marked
				// and asked about again in smaller calls; the next call goes.
				$refused++;
				foreach ( $chunk as $id => $here ) {
					if ( ! isset( $this->follow[ $id ] ) ) {
						continue;
					}
					$times = (int) ( $this->follow[ $id ]['refused'] ?? 0 ) + 1;
					if ( $times >= self::FOLLOW_REFUSED_MAX && (string) $id !== $solo ) {
						// Refused twice already, and not the run's one order
						// asked alone: it sits the next run out (fix round 6).
						$this->follow[ $id ]['rest'] = 1;
					}
					if ( $times < self::FOLLOW_REFUSED_MAX || 1 !== count( $chunk ) ) {
						$this->follow[ $id ]['refused'] = min( $times, self::FOLLOW_REFUSED_MAX - 1 );
						continue;
					}
					// Refused on its own a third time: dropped below, only if
					// eBay answered another call of this run (fix round 5).
					$to_drop[ $id ] = array( $times, $here, $result->get_error_message() );
				}
				continue;
			}

			$answered = true;
			foreach ( array_keys( $chunk ) as $id ) {
				if ( isset( $this->follow[ $id ] ) ) {
					$this->follow[ $id ]['checked'] = $now;
					unset( $this->follow[ $id ]['refused'], $this->follow[ $id ]['rest'] );
				}
			}

			$list = $result['OrderArray']['Order'] ?? array();
			if ( isset( $list['OrderID'] ) ) {
				$list = array( $list );
			}
			foreach ( (array) $list as $ebay_order ) {
				if ( ! is_array( $ebay_order ) ) {
					continue;
				}
				$id = $this->follow_key( $ebay_order, $chunk );
				if ( '' === $id ) {
					continue;
				}

				// An answer under a new OrderID - paid, or combined - that no
				// order here holds yet: the order here takes it now, as the
				// day's read would, so the day's read never creates the sale
				// again when it brings it later - after a failed or partial
				// read, or a payment between the read and this call (fix
				// round 5, MED-2).
				$new = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
				if ( '' !== $new && $new !== (string) $id && ! isset( $chunk[ $new ] ) && ! $this->order_exists( $new ) && ! $this->known_before( $new ) ) {
					$held = $this->followed_holding( $ebay_order );
					if ( ! empty( $held['all'] ) ) {
						$done = $this->take_new_id( $ebay_order, $held['all'] );
						if ( 'error' === $done ) {
							$failed++;
						} elseif ( 'paid' === $done ) {
							$paid_left++;
						} else {
							$changed++;
						}
						continue;
					}
				}

				$done = $this->revisit_safely( $ebay_order, $chunk[ $id ], $id );
				if ( 'error' === $done ) {
					$failed++;
				} elseif ( 'paid' === $done ) {
					$paid_left++;
				} elseif ( $done ) {
					$changed++;
				}
			}

			// No longer open: no longer followed under any ID.
			$still = array();
			foreach ( $this->follow as $entry ) {
				$still[ (int) ( $entry['wc'] ?? 0 ) ] = true;
			}
			foreach ( $chunk as $here ) {
				$closed += isset( $still[ (int) $here->get_id() ] ) ? 0 : 1;
			}
		}

		// An order refused on its own a third time is dropped only when eBay
		// answered another call this run: otherwise the refusal may be eBay's
		// own, for every order, and it stays to be asked again (fix round 5,
		// MED-1).
		foreach ( $to_drop as $id => $why ) {
			if ( ! $answered ) {
				$this->follow[ $id ]['refused'] = self::FOLLOW_REFUSED_MAX - 1;
				continue;
			}
			unset( $this->follow[ $id ] );
			$dropped++;
			TCGiant_Sync_Logger::warning( sprintf(
				'Order Import: eBay refused %d times to answer for eBay order %s (WC Order #%d) by its OrderID, the last time asked on its own, while it answered for others — %s. It is no longer followed: check it on eBay.',
				$why[0], $id, (int) $why[1]->get_id(), $why[2]
			) );
		}

		if ( $refused ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Order Import: eBay refused %d of the calls asking about open orders by their OrderID. Their orders are asked about again in smaller calls; one refused on its own while eBay answers others is no longer followed.',
				$refused
			) );
		}

		if ( $calls || $expired || $gone ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Order Import: %d open eBay order(s) asked about by their OrderID (%d call(s)): %d brought up to date%s%s, %d no longer open%s%s%s; %d still followed.',
				$asked, $calls, $changed,
				$paid_left ? sprintf( ', %d paid on eBay but left as set here (see the warnings)', $paid_left ) : '',
				$failed ? sprintf( ', %d not brought up to date (an error; each order\'s is logged once a day)', $failed ) : '',
				$closed,
				$expired ? sprintf( ', %d followed for 30 days and dropped', $expired ) : '',
				$gone ? sprintf( ', %d deleted here', $gone ) : '',
				$dropped ? sprintf( ', %d refused by eBay on its own and dropped', $dropped ) : '',
				count( $this->follow )
			) );
		}
	}

	/**
	 * revisit_order(), with a Throwable from it - another plugin's hook on
	 * the order's save or status change, say - logged, and only that order
	 * skipped (fix round 4, LOW-A), as create_wc_order() does. Its place on
	 * the follow list is put back as it was, so it is asked about again; an
	 * order that revisit_order() had just begun to follow stays followed, so
	 * the follow-up asks about it after its day (fix round 5). The error is
	 * logged once a day for each order (log_order_error()).
	 *
	 * @param array         $ebay_order Order from GetOrders.
	 * @param WC_Order|null $order      The order here, or null.
	 * @param string        $key        The eBay order ID it is followed by.
	 * @return bool|string As revisit_order(); 'error' when it threw.
	 */
	private function revisit_safely( $ebay_order, $order = null, $key = '' ) {
		$ebay_id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		$slot    = '' !== (string) $key ? (string) $key : $ebay_id;
		$was     = is_array( $this->follow ) && '' !== $slot && isset( $this->follow[ $slot ] ) ? $this->follow[ $slot ] : null;

		try {
			return $this->revisit_order( $ebay_order, $order, $key );
		} catch ( \Throwable $e ) {
			if ( is_array( $this->follow ) && '' !== $slot && null !== $was ) {
				$this->follow[ $slot ] = $was;
			}
			$this->log_order_error( $order ? (string) $order->get_id() : $slot, sprintf(
				'Order Import: eBay order %s could not be brought up to date here — %s. It is tried again next run.',
				$ebay_id, $e->getMessage()
			) );
			return 'error';
		}
	}

	/**
	 * Log an order's error once a day, not every run (fix round 5): an order
	 * whose save always throws is tried again every run while it is open,
	 * for up to 30 days.
	 *
	 * @param string $who     The order here, or its eBay order ID.
	 * @param string $message The error.
	 * @return void
	 */
	private function log_order_error( $who, $message ) {
		$name = 'tcgiant_order_error_' . substr( md5( (string) $who ), 0, 16 );
		if ( false !== get_transient( $name ) ) {
			return;
		}
		set_transient( $name, 1, DAY_IN_SECONDS );
		TCGiant_Sync_Logger::error( $message . ' While it lasts, this is logged once a day.' );
	}

	/**
	 * Give an order here the new OrderID eBay gave its sale, and bring it -
	 * and every other followed order holding a line of the sale - up to date
	 * (fix round 4, Q12).
	 *
	 * The first - the order holding most of the sale's items, then the one
	 * followed longest, then the lowest order number (fix round 5, LOW-2):
	 * the order made for the whole sale, or the first purchase of a Combined
	 * Invoice; followed_holding() puts it first - holds the new ID from now
	 * on, as eBay asks ("the order ID created upon buyer payment ... is the
	 * ID that the seller should use");
	 * the ID it had is kept as _ebay_order_id_was, so the day's read never
	 * creates it again, and its place on the follow list takes the new ID.
	 * Each other one - an order combined into a Combined Invoice - keeps its
	 * own, with a note.
	 *
	 * @param array $ebay_order The order from GetOrders, under its new ID.
	 * @param array $held       eBay order ID followed by => the order here.
	 * @return bool|string 'paid' when one was left as set by hand, 'error' when
	 *                     it threw; true otherwise.
	 */
	private function take_new_id( array $ebay_order, array $held ) {
		$new_id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		$first  = null;
		$result = true;

		try {
			foreach ( $held as $key => $order ) {
				$key = (string) $key;
				if ( null === $first ) {
					$first = $order;
					$old   = (string) $order->get_meta( '_ebay_order_id' );
					$order->add_meta_data( '_ebay_order_id_was', '' !== $old ? $old : $key, false );
					$order->update_meta_data( '_ebay_order_id', $new_id );
					$order->add_order_note( sprintf(
						/* translators: 1: the new eBay order ID, 2: the one it had */
						__( 'eBay gave this order a new order ID, %1$s (it was %2$s), as it does when an order is paid after it was made, or combined with others. TCGiant Sync follows it by its new ID.', 'tcgiant-sync' ),
						$new_id,
						'' !== $old ? $old : $key
					) );
					$order->save();
					if ( isset( $this->follow[ $key ] ) && $key !== $new_id ) {
						$this->follow[ $new_id ] = $this->follow[ $key ];
						unset( $this->follow[ $key ] );
						// The entry now stands for the whole sale: every line
						// of it, so a later new ID is matched whole (fix round 5).
						$this->follow[ $new_id ]['items'] = array_values( array_unique( array_merge( (array) ( $this->follow[ $new_id ]['items'] ?? array() ), $this->line_ids( $ebay_order ) ) ) );
						$this->index_lines( $new_id, (array) $this->follow[ $new_id ]['items'] );
					}
					TCGiant_Sync_Logger::log( sprintf(
						'Order Import: eBay order %s is WC Order #%d, imported before as eBay order %s - matched by its items; not created again.',
						$new_id, (int) $order->get_id(), '' !== $old ? $old : $key
					) );
					$key = $new_id;
				} else {
					$order->add_order_note( sprintf(
						/* translators: 1: the new eBay order ID, 2: the WooCommerce order that holds it */
						__( 'eBay combined this order with others into eBay order %1$s, which WC Order #%2$d holds here.', 'tcgiant-sync' ),
						$new_id,
						(int) $first->get_id()
					) );
					$order->save();
				}

				$done = $this->revisit_safely( $ebay_order, $order, $key );
				if ( 'error' === $done ) {
					$result = 'error';
				} elseif ( 'paid' === $done && true === $result ) {
					$result = 'paid';
				}
			}
		} catch ( \Throwable $e ) {
			$this->log_order_error( null !== $first ? (string) $first->get_id() : $new_id, sprintf(
				'Order Import: eBay order %s, held here under the ID eBay gave it before, could not be brought up to date — %s. It is tried again next run.',
				$new_id, $e->getMessage()
			) );
			return 'error';
		}

		return $result;
	}

	/**
	 * The followed orders holding lines of an eBay order not here by its ID
	 * (fix round 4, Q12).
	 *
	 * @param array $ebay_order An order from GetOrders.
	 * @return array 'all' => eBay order ID followed by => the order here, when
	 *               together they hold every line of it; 'some' => the same,
	 *               when they hold some of its lines but not all.
	 */
	private function followed_holding( array $ebay_order ) {
		$lines = array_values( array_unique( $this->line_ids( $ebay_order ) ) );
		if ( ! $lines || empty( $this->follow ) ) {
			return array();
		}

		$hit     = array();
		$share   = array();
		$covered = array();
		foreach ( $lines as $line ) {
			foreach ( $this->follow_lines[ $line ] ?? array() as $key => $unused ) {
				$entry = $this->follow[ $key ] ?? null;
				if ( ! is_array( $entry ) || ! in_array( $line, (array) ( $entry['items'] ?? array() ), true ) ) {
					continue;
				}
				if ( ! isset( $hit[ $key ] ) ) {
					$order = wc_get_order( (int) ( $entry['wc'] ?? 0 ) );
					if ( ! is_object( $order ) || ! method_exists( $order, 'update_status' ) || 'trash' === $order->get_status() ) {
						continue;
					}
					$hit[ $key ]   = $order;
					$share[ $key ] = 0;
				}
				$share[ $key ]++;
				$covered[ $line ] = true;
			}
		}

		if ( ! $hit ) {
			return array();
		}

		// The holder of most of the sale first, then the one followed longest,
		// then the lowest order number: the same order every time (fix round 5).
		$follow = $this->follow;
		uksort( $hit, function ( $a, $b ) use ( $share, $follow, $hit ) {
			if ( $share[ $a ] !== $share[ $b ] ) {
				return $share[ $b ] - $share[ $a ];
			}
			$since = (int) ( $follow[ $a ]['since'] ?? 0 ) - (int) ( $follow[ $b ]['since'] ?? 0 );
			return 0 !== $since ? $since : (int) $hit[ $a ]->get_id() - (int) $hit[ $b ]->get_id();
		} );

		return count( $covered ) === count( $lines ) ? array( 'all' => $hit ) : array( 'some' => $hit );
	}

	/**
	 * Index a followed order's line IDs (fix round 4).
	 *
	 * @param string $key   The eBay order ID it is followed by.
	 * @param array  $items Its line IDs.
	 * @return void
	 */
	private function index_lines( $key, array $items ) {
		foreach ( $items as $line ) {
			if ( is_string( $line ) && '' !== $line ) {
				$this->follow_lines[ $line ][ (string) $key ] = true;
			}
		}
	}

	/**
	 * Whether an order here had this eBay order ID before eBay gave it a new
	 * one (fix round 4, Q12).
	 *
	 * @param string $ebay_order_id eBay Order ID.
	 * @return bool
	 */
	private function known_before( $ebay_order_id ) {
		if ( empty( $ebay_order_id ) ) {
			return false;
		}

		global $wpdb;

		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table  = $wpdb->prefix . 'wc_orders_meta';
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE meta_key = '_ebay_order_id_was' AND meta_value = %s",
				$ebay_order_id
			) );
		} else {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_order_id_was' AND meta_value = %s",
				$ebay_order_id
			) );
		}

		return (int) $exists > 0;
	}

	/**
	 * Which followed order an answer is: by its OrderID, or - eBay's reference
	 * says an order's ID changes from unpaid to paid, and that either is
	 * accepted when asking - by a line of it.
	 *
	 * @param array $ebay_order An order from GetOrders.
	 * @param array $asked      The OrderIDs asked for, as keys.
	 * @return string The followed eBay order ID; '' when it is none of them.
	 */
	private function follow_key( array $ebay_order, array $asked ) {
		$id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		if ( '' !== $id && isset( $asked[ $id ] ) ) {
			return $id;
		}

		$lines = $this->line_ids( $ebay_order );
		foreach ( array_keys( $asked ) as $key ) {
			$had = (array) ( $this->follow[ $key ]['items'] ?? array() );
			if ( $lines && $had && array_intersect( $lines, $had ) ) {
				return (string) $key;
			}
		}

		return '';
	}

	/**
	 * An order's line IDs: OrderLineItemID, or ItemID-TransactionID.
	 *
	 * @param array $ebay_order An order from GetOrders.
	 * @return string[]
	 */
	private function line_ids( array $ebay_order ) {
		$ids = array();
		foreach ( (array) $this->get_transactions( $ebay_order ) as $tx ) {
			if ( ! is_array( $tx ) ) {
				continue;
			}
			$line = TCGiant_Sync_API::scalar_value( $tx['OrderLineItemID'] ?? '' );
			if ( '' === $line ) {
				$item = TCGiant_Sync_API::scalar_value( $tx['Item']['ItemID'] ?? '' );
				$txid = TCGiant_Sync_API::scalar_value( $tx['TransactionID'] ?? '' );
				$line = '' !== $item && '' !== $txid ? $item . '-' . $txid : '';
			}
			if ( '' !== $line ) {
				$ids[] = $line;
			}
		}
		return $ids;
	}

	/**
	 * Follow an order while eBay has it open, and stop once it does not.
	 *
	 * Open: Active and InProcess (not paid yet) and CancelPending (a
	 * cancellation asked for). A deleted order here is not followed.
	 * Outside an import run it does nothing.
	 *
	 * @param string         $key        The eBay order ID it is followed by.
	 * @param WC_Order|mixed $order      The order here.
	 * @param string         $status     eBay's OrderStatus now.
	 * @param array          $ebay_order The order from GetOrders.
	 * @return void
	 */
	private function follow_set( $key, $order, $status, array $ebay_order ) {
		if ( null === $this->follow || '' === (string) $key ) {
			return;
		}

		$open = in_array( strtolower( (string) $status ), array( 'active', 'inprocess', 'cancelpending' ), true )
			&& is_object( $order ) && method_exists( $order, 'get_status' ) && 'trash' !== $order->get_status();

		if ( ! $open ) {
			unset( $this->follow[ $key ] );
			return;
		}

		if ( ! isset( $this->follow[ $key ] ) ) {
			$this->follow[ $key ] = array(
				'wc'      => (int) $order->get_id(),
				'since'   => time(),
				'checked' => time(),
				'items'   => $this->line_ids( $ebay_order ),
			);
			$this->index_lines( (string) $key, $this->follow[ $key ]['items'] );
		}
	}

	/**
	 * The follow list as stored.
	 *
	 * @return array
	 */
	private function follow_list() {
		$list = get_option( self::FOLLOW_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Keep the follow list: what this run added, changed and removed, put
	 * onto the list as stored now, so a run that overlapped this one keeps
	 * what it did. At most FOLLOW_KEEP_MAX, the newest kept; written only
	 * when it changed, deleted when empty.
	 *
	 * @param array $was The list as this run read it.
	 * @return void
	 */
	private function save_follow( array $was ) {
		if ( ! is_array( $this->follow ) ) {
			return;
		}

		wp_cache_delete( self::FOLLOW_OPTION, 'options' );
		$stored = $this->follow_list();
		$list   = $stored;
		foreach ( array_keys( array_diff_key( $was, $this->follow ) ) as $id ) {
			unset( $list[ $id ] );
		}
		foreach ( $this->follow as $id => $entry ) {
			if ( ! isset( $was[ $id ] ) || $was[ $id ] !== $entry ) {
				$list[ $id ] = $entry;
			}
		}

		if ( count( $list ) > self::FOLLOW_KEEP_MAX ) {
			uasort( $list, function ( $a, $b ) {
				return (int) ( $b['since'] ?? 0 ) - (int) ( $a['since'] ?? 0 );
			} );
			$list = array_slice( $list, 0, self::FOLLOW_KEEP_MAX, true );
		}

		if ( $list === $stored ) {
			return;
		}
		if ( $list ) {
			update_option( self::FOLLOW_OPTION, $list, false );
		} else {
			delete_option( self::FOLLOW_OPTION );
		}
	}

	/**
	 * Check if an eBay order has already been imported.
	 *
	 * @param string $ebay_order_id eBay Order ID.
	 * @return bool
	 */
	private function order_exists( $ebay_order_id ) {
		if ( empty( $ebay_order_id ) ) {
			return false;
		}

		global $wpdb;

		// Check in HPOS orders meta if available, fall back to post meta.
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE meta_key = '_ebay_order_id' AND meta_value = %s",
				$ebay_order_id
			) );
		} else {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_order_id' AND meta_value = %s",
				$ebay_order_id
			) );
		}

		return (int) $exists > 0;
	}

	/**
	 * Create a WooCommerce order from eBay order data.
	 *
	 * @param array $ebay_order eBay order data from GetOrders.
	 * @return WC_Order|false Created order or false on failure.
	 */
	private function create_wc_order( $ebay_order ) {
		$order_id    = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		$buyer_email = TCGiant_Sync_API::scalar_value( $ebay_order['BuyerUserID'] ?? '' );
		$total       = TCGiant_Sync_API::money_value( $ebay_order['Total'] ?? 0 );
		$status      = TCGiant_Sync_API::scalar_value( $ebay_order['OrderStatus'] ?? '' );

		try {
			$wc_order = wc_create_order();
			if ( is_wp_error( $wc_order ) ) {
				TCGiant_Sync_Logger::error( 'Order Import: Failed to create WC order — ' . $wc_order->get_error_message() );
				return false;
			}

			// Set billing/shipping address.
			$address = $this->parse_address( $ebay_order );
			$wc_order->set_address( $address, 'billing' );
			$wc_order->set_address( $address, 'shipping' );

			// Set buyer email if available.
			$buyer_info = $ebay_order['TransactionArray']['Transaction'] ?? array();
			$buyer_transactions = isset( $buyer_info['Buyer'] ) ? array( $buyer_info ) : $buyer_info;

			if ( ! empty( $buyer_transactions ) ) {
				$first_tx = $buyer_transactions[0] ?? array();
				$buyer_data = $first_tx['Buyer'] ?? array();
				$email = TCGiant_Sync_API::scalar_value( $buyer_data['Email'] ?? '' );
				if ( ! empty( $email ) && is_email( $email ) ) {
					$wc_order->set_billing_email( $email );

					// Find or create customer.
					$customer_id = email_exists( $email );
					if ( $customer_id ) {
						$wc_order->set_customer_id( $customer_id );
					}
				}
			}

			// Add line items.
			$transactions = $this->get_transactions( $ebay_order );
			foreach ( $transactions as $tx ) {
				$this->add_line_item( $wc_order, $tx );
			}

			// Set shipping cost.
			$shipping_cost = TCGiant_Sync_API::money_value( $ebay_order['ShippingServiceSelected']['ShippingServiceCost'] ?? 0 );
			if ( $shipping_cost > 0 ) {
				$shipping_item = new WC_Order_Item_Shipping();
				$shipping_item->set_method_title( 'eBay Shipping' );
				$shipping_item->set_total( (string) $shipping_cost );
				$wc_order->add_item( $shipping_item );
			}

			// Set order totals.
			$wc_order->set_total( (string) $total );

			// Set order status.
			$wc_status = $this->map_order_status( $status );
			$wc_order->set_status( $wc_status );

			// Store eBay metadata.
			$wc_order->update_meta_data( '_ebay_order_id', $order_id );
			$wc_order->update_meta_data( '_ebay_order_source', 'ebay' );
			$wc_order->update_meta_data( '_ebay_buyer_user_id', $buyer_email );

			// eBay's status, and the one set here from it, so a later change
			// on eBay can be followed - and a status changed by hand left
			// alone (revisit_order(), 3.31.0).
			$wc_order->update_meta_data( '_ebay_order_status', $status );
			$wc_order->update_meta_data( '_ebay_order_wc_status', $wc_status );

			// Add order note.
			$wc_order->add_order_note( sprintf(
				/* translators: %s: eBay Order ID */
				__( 'Imported from eBay Order #%s', 'tcgiant-sync' ),
				$order_id
			) );

			$wc_order->save();

			TCGiant_Sync_Logger::log( sprintf(
				'Order Import: Created WC Order #%d from eBay Order %s ($%s)',
				$wc_order->get_id(), $order_id, number_format( $total, 2 )
			), 'success' );

			// Not paid yet, or a cancellation asked for: followed by its
			// OrderID until eBay says paid or cancelled (3.31.0).
			$this->follow_set( $order_id, $wc_order, $status, $ebay_order );

			return $wc_order;

		} catch ( \Throwable $e ) {
			// Throwable, not Exception: a TypeError from bad eBay data is an
			// Error, and slipped past this to kill the whole order import.
			TCGiant_Sync_Logger::error( 'Order Import: Exception — ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Parse address from eBay order data.
	 *
	 * @param array $ebay_order eBay order data.
	 * @return array WooCommerce address array.
	 */
	private function parse_address( $ebay_order ) {
		$shipping = $ebay_order['ShippingAddress'] ?? array();
		$shipping = is_array( $shipping ) ? $shipping : array();

		// eBay sends an empty field as an empty element, and the XML to array
		// conversion turns an empty element into an empty array - so a buyer
		// with no second address line had "Array" written into their order,
		// and WooCommerce logged "Array to string conversion" each time.
		$field = function ( $key ) use ( $shipping ) {
			return TCGiant_Sync_API::scalar_value( $shipping[ $key ] ?? '' );
		};

		$parts = explode( ' ', trim( $field( 'Name' ) ), 2 );

		return array(
			'first_name' => $parts[0] ?? '',
			'last_name'  => $parts[1] ?? '',
			'address_1'  => $field( 'Street1' ),
			'address_2'  => $field( 'Street2' ),
			'city'       => $field( 'CityName' ),
			'state'      => $field( 'StateOrProvince' ),
			'postcode'   => $field( 'PostalCode' ),
			'country'    => $field( 'Country' ),
			'phone'      => $field( 'Phone' ),
		);
	}

	/**
	 * Get transaction items from eBay order.
	 *
	 * @param array $ebay_order eBay order data.
	 * @return array Normalized array of transactions.
	 */
	private function get_transactions( $ebay_order ) {
		$tx_data = $ebay_order['TransactionArray']['Transaction'] ?? array();

		// Single transaction vs. multiple.
		if ( isset( $tx_data['TransactionID'] ) ) {
			return array( $tx_data );
		}

		return $tx_data;
	}

	/**
	 * Add a line item to a WC order from an eBay transaction.
	 *
	 * @param WC_Order $wc_order    WooCommerce order.
	 * @param array    $transaction eBay transaction data.
	 */
	private function add_line_item( $wc_order, $transaction ) {
		$item_data   = $transaction['Item'] ?? array();
		$ebay_item_id = TCGiant_Sync_API::scalar_value( $item_data['ItemID'] ?? '' );
		$title       = TCGiant_Sync_API::scalar_value( $item_data['Title'] ?? '' );
		$title       = '' !== $title ? $title : 'eBay Item';
		$qty         = (int) TCGiant_Sync_API::scalar_value( $transaction['QuantityPurchased'] ?? 1 );
		$qty         = max( 1, $qty );
		$price       = TCGiant_Sync_API::money_value( $transaction['TransactionPrice'] ?? 0 );

		// Try to match to a WC product.
		$wc_product = null;
		if ( ! empty( $ebay_item_id ) ) {
			global $wpdb;
			$product_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_item_id' AND meta_value = %s LIMIT 1",
				$ebay_item_id
			) );
			if ( $product_id ) {
				$wc_product = wc_get_product( $product_id );
			}
		}

		if ( $wc_product ) {
			$item = new WC_Order_Item_Product();
			$item->set_product( $wc_product );
			$item->set_quantity( $qty );
			$item->set_subtotal( (string) ( $price * $qty ) );
			$item->set_total( (string) ( $price * $qty ) );

			// Reduce stock with circular-update guard.
			TCGiant_Sync_Inventory::mark_ebay_origin( $wc_product->get_id() );
		} else {
			// Unmatched product — create a generic line item.
			$item = new WC_Order_Item_Product();
			$item->set_name( $title );
			$item->set_quantity( $qty );
			$item->set_subtotal( (string) ( $price * $qty ) );
			$item->set_total( (string) ( $price * $qty ) );
		}

		$item->add_meta_data( '_ebay_item_id', $ebay_item_id, true );
		$wc_order->add_item( $item );
	}

	/**
	 * Map eBay order status to WooCommerce order status.
	 *
	 * eBay's OrderStatus (OrderStatusCodeType): Active, the buyer has not
	 * paid; InProcess, not yet complete; Completed, paid; CancelPending, the
	 * buyer has asked to cancel and the seller has to answer; Cancelled;
	 * Inactive. Until 3.31.0 everything but Cancelled and Inactive was
	 * Processing, the status a shop ships.
	 *
	 * @param string $ebay_status eBay order status.
	 * @return string WC order status.
	 */
	private function map_order_status( $ebay_status ) {
		switch ( strtolower( (string) $ebay_status ) ) {
			case 'completed':
				return 'processing';
			case 'active':
			case 'inprocess':
				return self::UNPAID_STATUS;
			case 'cancelled':
			case 'cancelcomplete':
				return 'cancelled';
			case 'cancelpending':
			case 'inactive':
				return 'on-hold';
			default:
				return 'processing';
		}
	}

	/**
	 * Bring an order imported before up to date with eBay (3.31.0).
	 *
	 * Called for an order imported before, read again: in the last day's
	 * orders, or by its OrderID while it is followed. Paid for, cancelled, a
	 * cancellation asked for: its status here becomes what a
	 * new import of it would get - only while it is still the status this
	 * plugin set. A status changed by hand (Completed once shipped, say) is
	 * the shop's, and is left alone, with a note of what eBay now says.
	 *
	 * An order imported before 3.31.0 has no record of what was set. It is
	 * taken as the plugin's while it is still Processing (fix round 1: not
	 * On hold, which may be the shop's own). Still Processing and still
	 * unpaid, it goes to Pending payment, and when the buyer pays
	 * WooCommerce sends its new-order emails again. Nothing here changes
	 * stock: WooCommerce takes none for an eBay order, so it puts none back
	 * either (TCGiant_Sync_Inventory::block_double_reduction()); the sync
	 * follows eBay's own quantity.
	 *
	 * @param array         $ebay_order Order from GetOrders.
	 * @param WC_Order|null $order      The order here, when the caller has
	 *                                  it (the follow-up); found by the eBay
	 *                                  order ID otherwise.
	 * @param string        $key        The eBay order ID it is followed by,
	 *                                  when that is not the one eBay gave.
	 * @return bool|string Whether anything was changed or noted; 'paid' for
	 *                     a paid order left as the shop set it.
	 */
	private function revisit_order( $ebay_order, $order = null, $key = '' ) {
		$ebay_id = TCGiant_Sync_API::scalar_value( $ebay_order['OrderID'] ?? '' );
		$status  = TCGiant_Sync_API::scalar_value( $ebay_order['OrderStatus'] ?? '' );
		if ( '' === $ebay_id || '' === $status ) {
			return false;
		}

		if ( null === $order ) {
			$order = $this->find_order( $ebay_id );
		}
		if ( ! $order ) {
			return false;
		}

		// Followed while eBay still has it open; not once paid or cancelled.
		$this->follow_set( '' !== $key ? $key : $ebay_id, $order, $status, $ebay_order );

		$seen = (string) $order->get_meta( '_ebay_order_status' );
		if ( $seen === $status ) {
			return false;
		}

		$now  = (string) $order->get_status();
		$set  = (string) $order->get_meta( '_ebay_order_wc_status' );
		$want = $this->map_order_status( $status );

		// Imported before 3.31.0: taken as the plugin's only while still
		// Processing, which 3.30 set for every order but a cancelled or
		// inactive one. On hold may be the shop's own (fix round 1).
		if ( '' === $set && '' === $seen && 'processing' === $now ) {
			$set = $now;
		}

		$order->update_meta_data( '_ebay_order_status', $status );

		if ( '' === $set || $now !== $set ) {
			// Paid on eBay, but cancelled, failed, refunded or still waiting
			// for payment here: left as the shop set it, but said loudly - a
			// shop that ships from here would never ship it (fix round 1).
			if ( 'completed' === strtolower( $status ) && in_array( $now, array( 'cancelled', 'failed', 'refunded', 'pending' ), true ) ) {
				$order->add_order_note( sprintf(
					/* translators: 1: eBay's order status, e.g. Completed; 2: the order's status here */
					__( 'eBay now says this order is %1$s - paid for. Its status here is %2$s, set by hand, so TCGiant Sync left it as it is. Check it: the buyer has paid.', 'tcgiant-sync' ),
					$status,
					function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $now ) : $now
				) );
				$order->save();
				TCGiant_Sync_Logger::warning( sprintf(
					'Order Import: eBay order %s is now %s (paid), but WC Order #%d is %s here, set by hand - left as it is. Check it.',
					$ebay_id, $status, (int) $order->get_id(), $now
				) );
				return 'paid';
			}

			// The shop's own status: told, not changed - when eBay's says not
			// to ship it, or to wait. Paid needs no other note: a shop that
			// has moved an order on has moved past that. A deleted order is
			// left as it is, with nothing added.
			if ( 'trash' !== $now && 'processing' !== $want && $want !== $now ) {
				$order->add_order_note( sprintf(
					/* translators: %s: eBay's order status, e.g. Cancelled */
					__( 'eBay now says this order is %s. Its status here was changed by hand, so TCGiant Sync left it as it is.', 'tcgiant-sync' ),
					$status
				) );
				$order->save();
				return true;
			}
			$order->save();
			return false;
		}

		$order->update_meta_data( '_ebay_order_wc_status', $want );

		if ( $want === $now ) {
			$order->save();
			return false;
		}

		$order->update_status( $want, sprintf(
			/* translators: 1: eBay's order status, e.g. Completed; 2: the WooCommerce status it was set to */
			__( 'eBay now says this order is %1$s, so TCGiant Sync set it to %2$s.', 'tcgiant-sync' ),
			$status,
			function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $want ) : $want
		) );

		TCGiant_Sync_Logger::log( sprintf(
			'Order Import: eBay order %s is now %s - WC Order #%d set from %s to %s.',
			$ebay_id, $status, (int) $order->get_id(), $now, $want
		) );

		return true;
	}

	/**
	 * The WooCommerce order imported from an eBay order, or null.
	 *
	 * @param string $ebay_order_id eBay Order ID.
	 * @return WC_Order|null
	 */
	private function find_order( $ebay_order_id ) {
		global $wpdb;

		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table = $wpdb->prefix . 'wc_orders_meta';
			$id    = $wpdb->get_var( $wpdb->prepare(
				"SELECT order_id FROM {$table} WHERE meta_key = '_ebay_order_id' AND meta_value = %s ORDER BY order_id ASC LIMIT 1",
				$ebay_order_id
			) );
		} else {
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ebay_order_id' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
				$ebay_order_id
			) );
		}

		$order = $id ? wc_get_order( (int) $id ) : false;

		return is_object( $order ) && method_exists( $order, 'get_status' ) && method_exists( $order, 'update_status' ) ? $order : null;
	}

	/**
	 * Hook: woocommerce_order_needs_payment. An eBay order is never paid for
	 * on this website (3.31.0).
	 *
	 * @param bool          $needs Whether WooCommerce would take payment for it.
	 * @param WC_Order|mixed $order The order.
	 * @return bool
	 */
	public function not_paid_here( $needs, $order = null ) {
		if ( $needs && is_object( $order ) && method_exists( $order, 'get_meta' ) && 'ebay' === $order->get_meta( '_ebay_order_source' ) ) {
			return false;
		}
		return $needs;
	}

	/**
	 * Hook: woocommerce_valid_order_statuses_for_cancel. A buyer cancels an
	 * eBay order on eBay, not from their account on this website (3.31.0).
	 *
	 * @param array          $statuses The statuses a customer may cancel from.
	 * @param WC_Order|mixed $order    The order.
	 * @return array
	 */
	public function not_cancelled_here( $statuses, $order = null ) {
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) && 'ebay' === $order->get_meta( '_ebay_order_source' ) ) {
			return array();
		}
		return $statuses;
	}

	/**
	 * Push tracking info to eBay when WC order is marked completed.
	 *
	 * @param int $order_id WC Order ID.
	 */
	public function push_tracking_to_ebay( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$ebay_order_id = $order->get_meta( '_ebay_order_id' );
		if ( empty( $ebay_order_id ) ) {
			return; // Not an eBay order.
		}

		// Get tracking number from common tracking plugins.
		$tracking_number  = $order->get_meta( '_tracking_number' );
		$tracking_carrier = $order->get_meta( '_tracking_provider' );

		// Fallback to WooCommerce Shipment Tracking plugin format.
		if ( empty( $tracking_number ) ) {
			$tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
			if ( ! empty( $tracking_items ) && is_array( $tracking_items ) ) {
				$first = $tracking_items[0] ?? array();
				$tracking_number  = $first['tracking_number'] ?? '';
				$tracking_carrier = $first['tracking_provider'] ?? '';
			}
		}

		if ( empty( $tracking_number ) ) {
			TCGiant_Sync_Logger::log( sprintf(
				'Order #%d: No tracking number found. Skipping eBay tracking push.',
				$order_id
			) );
			return;
		}

		// Get eBay line item IDs from the order.
		$line_item_id = '';
		foreach ( $order->get_items() as $item ) {
			$ebay_item_id = $item->get_meta( '_ebay_item_id' );
			if ( ! empty( $ebay_item_id ) ) {
				$line_item_id = $ebay_item_id;
				break;
			}
		}

		if ( empty( $line_item_id ) ) {
			return;
		}

		// CompleteSale marks the buyer's order shipped on eBay and sends them
		// the tracking number. A staging copy completing a test order would
		// tell a real buyer their parcel was on its way. Refused here, before
		// the request is built, so a copy notes it once rather than logging an
		// error for every order it completes.
		$blocked = TCGiant_Sync_Environment::write_block();
		if ( $blocked ) {
			TCGiant_Sync_Environment::note_blocked( sprintf( 'Tracking for order #%d', $order_id ), $blocked );
			return;
		}

		$api = TCGiant_Sync_API::instance();
		$xml = '
<OrderID>' . esc_attr( $ebay_order_id ) . '</OrderID>
<Shipment>
<ShipmentTrackingDetails>
<ShipmentTrackingNumber>' . esc_attr( $tracking_number ) . '</ShipmentTrackingNumber>
<ShippingCarrierUsed>' . esc_attr( $tracking_carrier ?: 'Other' ) . '</ShippingCarrierUsed>
</ShipmentTrackingDetails>
</Shipment>
<Shipped>true</Shipped>';

		$result = $api->trading_api_request( 'CompleteSale', $xml );

		if ( is_wp_error( $result ) ) {
			TCGiant_Sync_Logger::error( sprintf(
				'Order #%d: Failed to push tracking to eBay — %s',
				$order_id, $result->get_error_message()
			) );
		} else {
			$order->add_order_note( sprintf(
				__( 'Tracking pushed to eBay: %s (%s)', 'tcgiant-sync' ),
				$tracking_number, $tracking_carrier ?: 'Other'
			) );
			TCGiant_Sync_Logger::log( sprintf(
				'Order #%d: Tracking pushed to eBay (%s).',
				$order_id, $tracking_number
			), 'success' );
		}
	}
}
