<?php
/**
 * What eBay itself holds for a listing, and whose listing it is (3.28.8).
 *
 * An Update of a listing first created on eBay rewrote eBay's own values with
 * the shop's defaults: a used part's condition 3000 became 1000, a PSA 10
 * slab became an ungraded card, and the listing's business policies,
 * location and handling time were reset. The push sent the same body for an
 * Add and a Revise, and the shop's defaults filled every field the product
 * did not override.
 *
 * Two records fix that:
 *
 * - _ebay_live: eBay's own values for the listing the product follows - its
 *   condition and descriptors, category, business policies, location, postal
 *   code, country, handling time and photos - from every full read of it
 *   (GetItem, GetSellerList), and from the GetItem a push or a relist makes
 *   of an ended listing. Bound to its item number: a snapshot of another
 *   listing is never used, and a read under a new item number replaces it
 *   rather than adding to it. A field an answer leaves out never erases one
 *   recorded.
 * - _ebay_origin: 'import' for a product the import made from an eBay
 *   listing, or one linked by hand to a listing no push of it made; 'push'
 *   for one whose listing a push created; with who said so
 *   (_ebay_origin_by). A product with neither, from before 3.28.8, is judged
 *   once (origin()) on what it holds and the answer kept: by a pass over
 *   them all at upgrade (sweep_origins()), or at its first push if that
 *   comes sooner. Where nothing it holds tells, nothing is kept, and its
 *   first Update reads the listing once and judges it by when it started.
 *
 * A product the plugin pushed follows the shop's defaults on every push, as
 * it always has. One imported keeps eBay's values: its revise sends only
 * what the shop overrode for it (TCGiant_Sync_Exporter::sends()), and a new
 * listing made for it carries eBay's values ahead of the defaults. The shop
 * can say otherwise for a product with _ebay_export_follow_defaults.
 *
 * Here rather than in the exporter: the import, which every edition ships,
 * writes the records.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Ebay_Live class.
 */
class TCGiant_Sync_Ebay_Live {

	/** eBay's own values for the listing the product follows. */
	const META = '_ebay_live';

	/** 'import' or 'push': who made the listing the product follows. */
	const ORIGIN_META = '_ebay_origin';

	/**
	 * Who wrote ORIGIN_META (3.28.8): BY_IMPORT, BY_LINK, BY_PUSH, BY_JUDGED
	 * or BY_SWEEP. An origin with none is from before anything said, and the
	 * upgrade's pass judges it again, as it does its own.
	 */
	const ORIGIN_BY_META = '_ebay_origin_by';

	/** The import, making a product from a listing or linking one by its SKU. */
	const BY_IMPORT = 'import';

	/** Link to this listing, by hand. */
	const BY_LINK = 'link';

	/** A push that created the listing. */
	const BY_PUSH = 'push';

	/** origin(), at a push: from what the product held, or what the push read of its listing. */
	const BY_JUDGED = 'judged';

	/** The upgrade's pass (sweep_origins()). */
	const BY_SWEEP = 'sweep';

	/**
	 * origin() of a product with a listing that nothing it holds can say who
	 * made: treated as imported - its Updates send only what it sets of its
	 * own - until a push reads the listing (learn_origin()).
	 */
	const UNKNOWN = 'unknown';

	/**
	 * What says the listing a product follows is a relist, or that its
	 * listing ended and was relisted or tried: the listing it replaced
	 * (follow_relist(), a push or RelistItem in place of an ended one), the
	 * relist's parent as the import read it, and Auto-Relist's records. Any
	 * one of them, and "made before its listing started" says nothing of
	 * who made the first listing (origin()).
	 */
	const RELIST_MARKS = array( '_ebay_previous_item_id', '_ebay_relist_parent_id', '_ebay_relist_tried', '_ebay_relist_held', '_ebay_relist_unsendable', '_ebay_relist_stock_check' );

	/**
	 * How long after its listing started the push that made it records the
	 * product's last full push (_ebay_export_last_pushed, once eBay has
	 * answered): the date rule's window (origin()).
	 */
	const PUSH_WINDOW = 900;

	/**
	 * And before: a server's clock a little behind eBay's.
	 */
	const PUSH_SKEW = 60;

	/**
	 * The shop's choice for one product: '1' sends the shop's defaults to
	 * eBay, '0' keeps eBay's values, '' (or none) goes by its origin.
	 */
	const FOLLOW_META = '_ebay_export_follow_defaults';

	/**
	 * How many entries the import keeps in a product's sync log
	 * (TCGiant_Sync_Mapper::save_as_product()). Fewer, and the first is
	 * still there.
	 */
	const SYNC_LOG_KEEPS = 20;

	/**
	 * The upgrade's pass over the products from before 3.28.8
	 * (sweep_origins()): how far it has got, what it found, and whether it
	 * is done.
	 */
	const SWEEP_OPTION = 'tcgiant_sync_origin_sweep_2';

	/**
	 * The 3.28.8 preview's pass, which kept 'push' where it could not tell:
	 * its verdicts carry no ORIGIN_BY_META, so SWEEP_OPTION's pass judges
	 * them again. Only removed now, on uninstall.
	 */
	const SWEEP_OPTION_PREVIEW = 'tcgiant_sync_origin_sweep';

	/** Products the pass judges in one request. */
	const SWEEP_BATCH = 500;

	/**
	 * Transient that holds the pass back for a minute after an admin page
	 * ran it: it runs from the hourly check, and from admin pages at most once
	 * a minute, never from an AJAX request or the heartbeat.
	 */
	const SWEEP_TICK = 'tcgiant_sync_origin_sweep_tick';

	/** Sync logs the pass reads in one query. */
	const SWEEP_LOG_CHUNK = 50;

	/**
	 * What eBay's answer about a listing says of the fields a push would
	 * otherwise fill from the shop's defaults. Only the fields the answer
	 * carries, each as a plain string: a field it leaves out is not in the
	 * result, so merge() keeps what an earlier answer said of it.
	 *
	 * @param mixed $item A listing as eBay sent it (GetItem, GetSellerList).
	 * @return array item_id, condition_id, condition_description, descriptors
	 *               (each name with its value or info), category_id,
	 *               category_name, policies (shipping, return, payment),
	 *               location, postal_code, country, dispatch_time, pictures.
	 */
	public static function from_item( $item ) {
		if ( ! is_array( $item ) ) {
			return array();
		}

		$live   = array();
		$fields = array(
			'item_id'               => $item['ItemID'] ?? '',
			'condition_id'          => $item['ConditionID'] ?? '',
			'condition_description' => $item['ConditionDescription'] ?? '',
			'category_id'           => $item['PrimaryCategory']['CategoryID'] ?? '',
			'category_name'         => $item['PrimaryCategory']['CategoryName'] ?? '',
			'location'              => $item['Location'] ?? '',
			'postal_code'           => $item['PostalCode'] ?? '',
			'country'               => $item['Country'] ?? '',
			'dispatch_time'         => $item['DispatchTimeMax'] ?? '',
		);
		foreach ( $fields as $key => $raw ) {
			$value = trim( TCGiant_Sync_API::scalar_value( $raw ) );
			if ( '' !== $value ) {
				$live[ $key ] = $value;
			}
		}

		// The descriptors, only when the answer carries the container: one
		// descriptor alone comes as itself, not as a list of one.
		if ( isset( $item['ConditionDescriptors'] ) && is_array( $item['ConditionDescriptors'] ) ) {
			$list = $item['ConditionDescriptors']['ConditionDescriptor'] ?? array();
			$list = is_array( $list ) && isset( $list['Name'] ) ? array( $list ) : ( is_array( $list ) ? $list : array() );
			$out  = array();
			foreach ( $list as $one ) {
				if ( ! is_array( $one ) ) {
					continue;
				}
				$name = trim( TCGiant_Sync_API::scalar_value( $one['Name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$entry = array( 'name' => $name );
				$value = $one['Value'] ?? null;
				if ( is_array( $value ) && isset( $value[0] ) ) {
					$value = $value[0];
				}
				$value = trim( TCGiant_Sync_API::scalar_value( $value ) );
				if ( '' !== $value ) {
					$entry['value'] = $value;
				}
				$info = trim( TCGiant_Sync_API::scalar_value( $one['AdditionalInfo'] ?? '' ) );
				if ( '' !== $info ) {
					$entry['info'] = $info;
				}
				$out[] = $entry;
			}
			$live['descriptors'] = $out;
		}

		// The business policies, only when the answer carries them: eBay gives
		// SellerProfiles to the listing's own seller.
		if ( isset( $item['SellerProfiles'] ) && is_array( $item['SellerProfiles'] ) ) {
			$profiles = $item['SellerProfiles'];
			$policies = array();
			foreach ( array(
				'shipping' => $profiles['SellerShippingProfile']['ShippingProfileID'] ?? '',
				'return'   => $profiles['SellerReturnProfile']['ReturnProfileID'] ?? '',
				'payment'  => $profiles['SellerPaymentProfile']['PaymentProfileID'] ?? '',
			) as $kind => $raw ) {
				$id = trim( TCGiant_Sync_API::scalar_value( $raw ) );
				if ( '' !== $id ) {
					$policies[ $kind ] = $id;
				}
			}
			if ( $policies ) {
				$live['policies'] = $policies;
			}
		}

		// The photos, in eBay's order, as the image localizer records the
		// address each was downloaded from: without its query string.
		if ( isset( $item['PictureDetails']['PictureURL'] ) ) {
			$urls     = (array) $item['PictureDetails']['PictureURL'];
			$pictures = array();
			foreach ( $urls as $url ) {
				$url = trim( (string) preg_replace( '/\?.*$/', '', TCGiant_Sync_API::scalar_value( $url ) ) );
				if ( '' !== $url ) {
					$pictures[] = $url;
				}
			}
			if ( $pictures ) {
				$live['pictures'] = $pictures;
			}
		}

		return $live;
	}

	/**
	 * A fresh answer laid over what is recorded, for the listing $item_id.
	 *
	 * A record of another listing - one the product has since moved on from,
	 * by a relist - is replaced, never merged. Within one listing, a field the
	 * answer leaves out keeps what was recorded.
	 *
	 * @param mixed  $stored  What _ebay_live holds.
	 * @param array  $fresh   from_item() of the answer.
	 * @param string $item_id The listing the answer is about.
	 * @return array
	 */
	public static function merge( $stored, array $fresh, $item_id ) {
		$item_id = (string) $item_id;
		if ( ! is_array( $stored ) || (string) ( $stored['item_id'] ?? '' ) !== $item_id ) {
			$fresh['item_id'] = $item_id;
			return $fresh;
		}

		$merged            = array_merge( $stored, $fresh );
		$merged['item_id'] = $item_id;

		// A new condition with no descriptors is not the one the recorded
		// descriptors were of: a graded card moved to 3000 on eBay keeps none
		// of its grade here either.
		if ( isset( $fresh['condition_id'] ) && ! isset( $fresh['descriptors'] ) && $fresh['condition_id'] !== (string) ( $stored['condition_id'] ?? '' ) ) {
			unset( $merged['descriptors'] );
		}

		return $merged;
	}

	/**
	 * eBay's values for the listing a product follows now, or none: a record
	 * of a listing it no longer follows is not its listing's.
	 *
	 * @param int $product_id Product.
	 * @return array
	 */
	public static function snapshot( $product_id ) {
		$item = (string) get_post_meta( (int) $product_id, '_ebay_item_id', true );
		$live = get_post_meta( (int) $product_id, self::META, true );

		return ( '' !== $item && is_array( $live ) && $item === (string) ( $live['item_id'] ?? '' ) ) ? $live : array();
	}

	/**
	 * Record what an answer of eBay's says of the listing a product follows,
	 * written only when it changes anything. An answer about another listing
	 * is not recorded.
	 *
	 * @param int   $product_id Product.
	 * @param mixed $item       A listing as eBay sent it.
	 * @return bool Whether anything was written.
	 */
	public static function record( $product_id, $item ) {
		$product_id = (int) $product_id;
		$fresh      = self::from_item( $item );
		$item_id    = (string) get_post_meta( $product_id, '_ebay_item_id', true );

		// An answer that says nothing but the item number says nothing worth
		// keeping.
		if ( count( $fresh ) < 2 || '' === $item_id || $item_id !== (string) ( $fresh['item_id'] ?? '' ) ) {
			return false;
		}

		$stored = get_post_meta( $product_id, self::META, true );
		$merged = self::merge( $stored, $fresh, $item_id );
		if ( $merged === $stored ) {
			return false;
		}

		update_post_meta( $product_id, self::META, $merged );

		return true;
	}

	/**
	 * Record a push's own GetItem of the listing a product follows, read
	 * because a value the push needs had not been read (3.28.8,
	 * TCGiant_Sync_Exporter::live_unknown_groups()). GetItem gives every
	 * container a listing has, so one it leaves out the listing has none of:
	 * no descriptors, no business policies. The record is marked read whole
	 * ('full'), and a push never asks for that listing again; a record of
	 * another listing - a relist - is not.
	 *
	 * @param int   $product_id Product.
	 * @param mixed $item       GetItem's Item.
	 * @return bool Whether it was this product's listing, and so recorded.
	 */
	public static function record_read( $product_id, $item ) {
		$product_id = (int) $product_id;
		$fresh      = self::from_item( $item );
		$item_id    = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		if ( '' === $item_id || $item_id !== (string) ( $fresh['item_id'] ?? '' ) ) {
			return false;
		}

		$fresh        += array( 'descriptors' => array(), 'policies' => array() );
		$fresh['full'] = '1';
		$stored        = get_post_meta( $product_id, self::META, true );
		$merged        = self::merge( $stored, $fresh, $item_id );
		if ( $merged !== $stored ) {
			update_post_meta( $product_id, self::META, $merged );
		}

		return true;
	}

	/**
	 * Who made the listing a product follows: 'import', 'push', UNKNOWN when
	 * nothing the product holds can tell, or '' for a product with no
	 * listing.
	 *
	 * Recorded from 3.28.8, with who recorded it (ORIGIN_BY_META): 'import'
	 * when the import makes a product from a listing, or the shop links one
	 * by hand that no push of it made; 'push' when a push creates one. A
	 * product from before then is judged by what it holds - only on evidence,
	 * every import rule first, never 'push' for want of it:
	 *
	 * - never pushed successfully - no last-pushed date, not marked pushed:
	 *   the import, or Link, gave it its listing. Not by a pushed version
	 *   alone, which Unlink and a duplicate leave behind;
	 * - made after its listing started (_ebay_start_time; where the product
	 *   holds none, the Listings table's start for the same listing): a push
	 *   creates a listing for a product that already exists. The table is
	 *   read only this way: every push, an Update too, writes its own time
	 *   there;
	 * - its _ebay_sku row written before its _ebay_item_id row (sku_first),
	 *   whatever the SKU. Only the import's own save writes _ebay_sku - for
	 *   a product it makes, and for a shop product its SKU links to a
	 *   listing - and it writes _ebay_sku first, as it has in every version.
	 *   A push writes _ebay_item_id and no _ebay_sku, so a product a push
	 *   linked first gets its _ebay_sku later, from the first sync after;
	 *   relists and the import's re-link of an ended listing update both in
	 *   place; Unlink and a duplicate drop both. The one way a pushed
	 *   product reads as imported by this - Unlink, then the import linking
	 *   it again by its SKU - is an import link;
	 * - a sync log that still holds its first entry, the import's "New
	 *   product imported";
	 *
	 * and only then, as the shop's: the listing carries this product's own
	 * push mark (3.28.7), as a read of it shows; or a push made this
	 * listing and nothing was pushed to it since - the product made before
	 * the listing started, the listing no relist (no RELIST_MARKS on the
	 * product, no RelistParentID in the read), and the product's last full
	 * push (_ebay_export_last_pushed) recorded within PUSH_WINDOW after the
	 * listing started, as the push that made it records it. Anything else
	 * those dates show is 'import': a product Updated since, a listing
	 * linked by hand before 3.28.8 or by the import's re-link over an ended
	 * one, a relist - none of them can be told from a listing made on eBay.
	 *
	 * Where its listing's start is not known, nor its own date, the product
	 * is UNKNOWN, and nothing is kept for it: its Updates send only what it
	 * sets of its own, as an imported one's do, until the push reads its
	 * listing once with GetItem (TCGiant_Sync_Exporter::live_unknown_groups())
	 * and judges it by the same rules (learn_origin()); a read that still
	 * cannot tell keeps 'import'.
	 *
	 * Not by the mark 3.28.0 put on every auction ever pushed
	 * (_ebay_auction_listed_item): an imported auction the shop only updated
	 * carries it too, and it moves with a relist.
	 *
	 * A product the shop pushed and then Updated, whose listing was
	 * relisted, or whose date was changed after its listing started, reads
	 * as imported: its Updates then leave the listing's values as they are,
	 * which harms nothing. The shop can say otherwise on its eBay tab.
	 *
	 * @param int  $product_id Product.
	 * @param bool $persist    Keep the answer, for a product from before
	 *                         3.28.8, so a push that marks it pushed cannot
	 *                         change it: a push's own. UNKNOWN is never kept.
	 * @return string
	 */
	public static function origin( $product_id, $persist = false ) {
		$product_id = (int) $product_id;
		$stored     = (string) get_post_meta( $product_id, self::ORIGIN_META, true );
		if ( 'import' === $stored || 'push' === $stored ) {
			return $stored;
		}

		$item_id = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		if ( '' === $item_id ) {
			return '';
		}

		$origin = self::judge_origin( $product_id, $item_id );
		if ( '' === $origin ) {
			return self::UNKNOWN;
		}
		if ( $persist ) {
			update_post_meta( $product_id, self::ORIGIN_META, $origin );
			update_post_meta( $product_id, self::ORIGIN_BY_META, self::BY_JUDGED );
		}

		return $origin;
	}

	/**
	 * What a push's GetItem of a product's listing says of who made it
	 * (3.28.8): when it started, recorded as _ebay_start_time; whether it is
	 * a relist (RelistParentID); whether it carries this product's push mark
	 * (ApplicationData, 3.28.7). The origin is judged with those and kept,
	 * where the product held none; a completed read that still cannot tell
	 * keeps 'import' - nothing of the shop's is then sent - so the listing is
	 * not read again. Only an answer about the listing the product follows.
	 *
	 * @param int   $product_id Product.
	 * @param mixed $item       GetItem's Item.
	 * @param bool  $told       Set: whether the rules told, rather than the
	 *                          read's default.
	 * @return string origin() afterwards.
	 */
	public static function learn_origin( $product_id, $item, &$told = null ) {
		$product_id = (int) $product_id;
		$told       = false;
		$item_id    = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		if ( ! is_array( $item ) || '' === $item_id || $item_id !== trim( TCGiant_Sync_API::scalar_value( $item['ItemID'] ?? '' ) ) ) {
			return self::origin( $product_id );
		}
		$stored = (string) get_post_meta( $product_id, self::ORIGIN_META, true );
		if ( 'import' === $stored || 'push' === $stored ) {
			$told = true;
			return $stored;
		}

		$start = trim( TCGiant_Sync_API::scalar_value( $item['ListingDetails']['StartTime'] ?? '' ) );
		if ( '' !== $start && $start !== (string) get_post_meta( $product_id, '_ebay_start_time', true ) ) {
			update_post_meta( $product_id, '_ebay_start_time', $start );
		}

		$verdict = self::judge_origin( $product_id, $item_id, array(
			'relisted' => '' !== trim( TCGiant_Sync_API::scalar_value( $item['RelistParentID'] ?? '' ) ),
			'marked'   => $product_id === TCGiant_Sync_API::marked_product( $item['ApplicationData'] ?? '' ),
		) );
		$told = '' !== $verdict;
		if ( ! $told ) {
			$verdict = 'import';
		}
		update_post_meta( $product_id, self::ORIGIN_META, $verdict );
		update_post_meta( $product_id, self::ORIGIN_BY_META, self::BY_JUDGED );

		return $verdict;
	}

	/**
	 * origin() for a product from before 3.28.8: the facts read one product
	 * at a time, the costly ones only when a rule needs them.
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    The listing it follows.
	 * @param array  $read       What a read of the listing said:
	 *                           relisted, marked (learn_origin()).
	 * @return string 'import', 'push', or '' when nothing tells.
	 */
	private static function judge_origin( $product_id, $item_id, array $read = array() ) {
		$facts = array(
			'item_id'     => $item_id,
			'last_pushed' => (string) get_post_meta( $product_id, '_ebay_export_last_pushed', true ),
			'status'      => (string) get_post_meta( $product_id, '_ebay_export_status', true ),
			'start'       => (string) get_post_meta( $product_id, '_ebay_start_time', true ),
			'made'        => function_exists( 'get_post_field' ) ? (string) get_post_field( 'post_date_gmt', $product_id ) : '',
			'table_start' => function () use ( $product_id, $item_id ) {
				return self::table_start( $product_id, $item_id );
			},
			'sku_first'   => function () use ( $product_id ) {
				return self::ebay_sku_first( $product_id );
			},
			'relisted'    => function () use ( $product_id, $read ) {
				if ( ! empty( $read['relisted'] ) ) {
					return true;
				}
				foreach ( self::RELIST_MARKS as $key ) {
					if ( ! empty( get_post_meta( $product_id, $key, true ) ) ) {
						return true;
					}
				}
				return false;
			},
			'marked'      => ! empty( $read['marked'] ),
		);

		$verdict = self::judge_before_log( $facts );
		if ( '' !== $verdict ) {
			return $verdict;
		}

		return self::judge_after_log( $facts, get_post_meta( $product_id, '_tcgiant_sync_log', true ) );
	}

	/**
	 * The rules before the sync log, as origin() and the upgrade's pass both
	 * read them: never pushed; made after its listing started, by the product
	 * or the Listings table; _ebay_sku recorded before the item number. A
	 * fact may be given as a closure, asked only when its rule is reached.
	 *
	 * @param array $facts item_id, last_pushed, status, start, made (the
	 *                     product's post_date_gmt), table_start, sku_first,
	 *                     relisted, marked.
	 * @return string 'import', or '' when the log and what follows decide.
	 */
	private static function judge_before_log( array $facts ) {
		if ( '' === (string) self::fact( $facts, 'last_pushed' ) && 'pushed' !== (string) self::fact( $facts, 'status' ) ) {
			return 'import';
		}

		$made  = self::made_time( (string) self::fact( $facts, 'made' ) );
		$start = (string) self::fact( $facts, 'start' );
		if ( $made ) {
			$started = '' !== $start ? strtotime( $start ) : false;
			if ( $started && $made > $started ) {
				return 'import';
			}
			if ( '' === $start ) {
				$table = (string) self::fact( $facts, 'table_start' );
				$listed = '' !== $table ? strtotime( $table ) : false;
				if ( $listed && $made > $listed ) {
					return 'import';
				}
			}
		}

		if ( true === self::fact( $facts, 'sku_first' ) ) {
			return 'import';
		}

		return '';
	}

	/**
	 * The rules from the sync log on: its first entry the import's is
	 * 'import' - the last import rule. Then the shop's, on evidence only:
	 * its own push mark on the listing, as a read showed it; or a push made
	 * this listing and was its last full push (pushed_it()). Where the
	 * product's date and the listing's start (by its own _ebay_start_time -
	 * never the table's) are both known, anything else is 'import'; else ''.
	 *
	 * @param array $facts judge_before_log()'s.
	 * @param mixed $log   _tcgiant_sync_log.
	 * @return string
	 */
	private static function judge_after_log( array $facts, $log ) {
		if ( self::log_says_imported( $log ) ) {
			return 'import';
		}
		if ( true === self::fact( $facts, 'marked' ) ) {
			return 'push';
		}

		$made    = self::made_time( (string) self::fact( $facts, 'made' ) );
		$start   = (string) self::fact( $facts, 'start' );
		$started = '' !== $start ? strtotime( $start ) : false;
		if ( ! $made || ! $started ) {
			return '';
		}

		return self::pushed_it( $started, $facts ) ? 'push' : 'import';
	}

	/**
	 * Whether the push that made a listing is the product's last full push:
	 * the listing no relist, and _ebay_export_last_pushed - written by
	 * record_listing_made() for every push eBay takes (an Add, an Update, a
	 * new listing in place of an ended one), never by a stock change
	 * (ReviseInventoryStatus) nor a relist of Auto-Relist's - from PUSH_SKEW
	 * before the listing started to PUSH_WINDOW after it. An Update since, a
	 * Link, a re-link: later, or long before.
	 *
	 * @param int   $started The listing's StartTime, as a time.
	 * @param array $facts   The facts: last_pushed, relisted.
	 * @return bool
	 */
	private static function pushed_it( $started, array $facts ) {
		if ( true === self::fact( $facts, 'relisted' ) ) {
			return false;
		}
		$pushed = self::site_time( (string) self::fact( $facts, 'last_pushed' ) );

		return $pushed && $pushed >= $started - self::PUSH_SKEW && $pushed <= $started + self::PUSH_WINDOW;
	}

	/**
	 * A time the site recorded in its own zone - _ebay_export_last_pushed is
	 * current_time( 'mysql' ) in every version - as a UTC timestamp, by the
	 * site's time zone (get_gmt_from_date(), as the inventory reads its
	 * own). eBay's StartTime is UTC already.
	 *
	 * @param string $local 'Y-m-d H:i:s', the site's time.
	 * @return int|false
	 */
	private static function site_time( $local ) {
		$local = trim( $local );
		if ( '' === $local ) {
			return false;
		}
		$gmt = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $local ) : $local;

		return is_string( $gmt ) && '' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
	}

	/**
	 * A fact of judge_before_log()'s, asking a closure for it.
	 *
	 * @param array  $facts The facts.
	 * @param string $key   Which.
	 * @return mixed
	 */
	private static function fact( array $facts, $key ) {
		$value = $facts[ $key ] ?? '';

		return $value instanceof Closure ? $value() : $value;
	}

	/**
	 * A product's post_date_gmt as a time, or false for none or a draft's
	 * zero date.
	 *
	 * @param string $made_gmt post_date_gmt.
	 * @return int|false
	 */
	private static function made_time( $made_gmt ) {
		return ( '' !== $made_gmt && 0 !== strpos( $made_gmt, '0000' ) ) ? strtotime( $made_gmt . ' UTC' ) : false;
	}

	/**
	 * Whether a product's _ebay_sku row was written before its _ebay_item_id
	 * row: as the import's own save writes them, and never for a product a
	 * push linked before any import touched it (see origin()). Of duplicate
	 * rows, the first.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	private static function ebay_sku_first( $product_id ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_ebay_sku', '_ebay_item_id') ORDER BY meta_id ASC",
			(int) $product_id
		), ARRAY_A );

		$first = array();
		foreach ( $rows as $row ) {
			$key = is_array( $row ) ? (string) ( $row['meta_key'] ?? '' ) : '';
			if ( '' !== $key && ! isset( $first[ $key ] ) ) {
				$first[ $key ] = (int) ( $row['meta_id'] ?? 0 );
			}
		}

		return isset( $first['_ebay_sku'], $first['_ebay_item_id'] ) && $first['_ebay_sku'] < $first['_ebay_item_id'];
	}

	/**
	 * When the Listings table says the product's listing started, for the
	 * listing it follows now; '' when it says nothing, or speaks of another.
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    The listing it follows.
	 * @return string
	 */
	private static function table_start( $product_id, $item_id ) {
		if ( ! class_exists( 'TCGiant_Sync_DB' ) ) {
			return '';
		}
		$row = TCGiant_Sync_DB::get_by_product( (int) $product_id );

		return ( is_array( $row ) && (string) ( $row['ebay_item_id'] ?? '' ) === (string) $item_id ) ? (string) ( $row['ebay_start_time'] ?? '' ) : '';
	}

	/**
	 * The sync log rule: a log of fewer than SYNC_LOG_KEEPS entries whose
	 * oldest is the import's "New product imported".
	 *
	 * @param mixed $log _tcgiant_sync_log.
	 * @return bool
	 */
	private static function log_says_imported( $log ) {
		if ( ! is_array( $log ) || count( $log ) >= self::SYNC_LOG_KEEPS ) {
			return false;
		}
		$first = end( $log );

		return is_array( $first ) && in_array( 'New product imported. All fields synced from eBay.', (array) ( $first['decisions'] ?? array() ), true );
	}

	/**
	 * The upgrade's pass from an admin page: at most once a minute, and
	 * never from an AJAX request - the heartbeat included - which can come
	 * every few seconds and beside an import's own batch.
	 *
	 * @return void
	 */
	public static function sweep_origins_from_admin() {
		if ( self::doing_ajax() ) {
			return;
		}
		$state = get_option( self::SWEEP_OPTION, array() );
		if ( ( is_array( $state ) && ! empty( $state['done'] ) ) || get_transient( self::SWEEP_TICK ) ) {
			return;
		}
		set_transient( self::SWEEP_TICK, 1, 60 );

		self::sweep_origins();
	}

	/**
	 * Once, at upgrade (3.28.8): who made the listing each product linked
	 * before 3.28.8 follows, judged and kept while what it holds is freshest
	 * - before a relist moves its listing's start on, or its sync log fills.
	 * From the hourly check, and from admin pages at most once a minute
	 * (sweep_origins_from_admin()), SWEEP_BATCH products at a time from where
	 * the last batch stopped; done when one comes back short, and said in
	 * the log. Asks eBay nothing.
	 *
	 * The products it judges: those with a listing and no origin, and those
	 * whose origin no one said who wrote (the 3.28.8 preview's pass, which
	 * kept 'push' where it could not tell) or a pass wrote - never one a push,
	 * a link, the import or a push's own judgement wrote (ORIGIN_BY_META).
	 * Judged as origin() judges, by the same rules from what its queries
	 * select: the products' IDs, dates and origins, then the meta the rules
	 * read, the Listings table's starts for those with none, then,
	 * SWEEP_LOG_CHUNK at a time, the sync logs of those the first rules leave
	 * open, only the ones that can say 'import' - never by loading products,
	 * posts or their whole meta, and each query's rows freed as it goes. Not
	 * inside an AJAX request. A verdict is kept with who wrote it, 'sweep';
	 * where nothing tells, nothing is kept, and an origin a pass kept before
	 * goes. A request stopped part way leaves what it kept, and the next takes
	 * the rest.
	 *
	 * @return void
	 */
	public static function sweep_origins() {
		// Never inside an AJAX request - "Process Queue" runs the hourly
		// check's hooks from one, as the heartbeat would.
		if ( self::doing_ajax() ) {
			return;
		}

		$state = get_option( self::SWEEP_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( ! empty( $state['done'] ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_date_gmt, o.meta_value AS origin FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} li ON li.post_id = p.ID AND li.meta_key = '_ebay_item_id' AND li.meta_value <> ''
			 LEFT JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
			 WHERE p.post_type = 'product' AND p.ID > %d AND ( o.meta_id IS NULL OR b.meta_id IS NULL OR b.meta_value = %s )
			 ORDER BY p.ID ASC LIMIT %d",
			self::ORIGIN_META,
			self::ORIGIN_BY_META,
			(int) ( $state['after'] ?? 0 ),
			self::BY_SWEEP,
			self::SWEEP_BATCH
		), ARRAY_A );

		$made = array();
		$was  = array();
		foreach ( $rows as $row ) {
			$made[ (int) $row['ID'] ] = (string) $row['post_date_gmt'];
			if ( isset( $row['origin'] ) && ! isset( $was[ (int) $row['ID'] ] ) ) {
				$was[ (int) $row['ID'] ] = (string) $row['origin'];
			}
		}
		$found = count( $rows );
		unset( $rows );

		$mids   = array();
		$meta   = self::sweep_meta( array_keys( $made ), array_merge( array( '_ebay_item_id', '_ebay_export_last_pushed', '_ebay_export_status', '_ebay_start_time', '_ebay_sku' ), self::RELIST_MARKS ), false, $mids );
		$tables = self::sweep_table_starts( array_keys( array_filter( $made, function ( $date, $id ) use ( $meta ) {
			return '' === (string) ( $meta[ $id ]['_ebay_start_time'] ?? '' );
		}, ARRAY_FILTER_USE_BOTH ) ) );
		$open   = array();
		foreach ( $made as $id => $made_gmt ) {
			$facts = self::sweep_facts( $id, $made_gmt, $meta[ $id ] ?? array(), $mids[ $id ] ?? array(), $tables[ $id ] ?? array() );
			unset( $meta[ $id ], $mids[ $id ], $tables[ $id ] );
			if ( 'import' === self::judge_before_log( $facts ) ) {
				self::keep_swept( $id, 'import', $was[ $id ] ?? null, $state );
				continue;
			}
			// What the rules after the log read, and no more.
			$open[ $id ] = array( 'made' => $facts['made'], 'start' => $facts['start'], 'relisted' => $facts['relisted'], 'last_pushed' => $facts['last_pushed'] );
		}
		unset( $meta, $mids, $tables, $facts );

		// The logs, of only those that can say 'import' - fewer than
		// SYNC_LOG_KEEPS entries (a full log is serialized as "a:20:"), with
		// the import's first entry in it - and SWEEP_LOG_CHUNK at a time, so
		// a batch never holds more than a few. log_says_imported() decides.
		foreach ( array_chunk( array_keys( $open ), self::SWEEP_LOG_CHUNK ) as $chunk ) {
			$none = array();
			$logs = self::sweep_meta( $chunk, array( '_tcgiant_sync_log' ), true, $none );
			foreach ( $chunk as $id ) {
				$log = isset( $logs[ $id ]['_tcgiant_sync_log'] ) ? maybe_unserialize( $logs[ $id ]['_tcgiant_sync_log'] ) : '';
				unset( $logs[ $id ] );
				self::keep_swept( $id, self::judge_after_log( $open[ $id ], $log ), $was[ $id ] ?? null, $state );
				unset( $log, $open[ $id ] );
			}
			unset( $logs );
			if ( method_exists( $wpdb, 'flush' ) ) {
				$wpdb->flush();
			}
		}

		if ( $made ) {
			$state['after'] = max( array_keys( $made ) );
		}
		unset( $made, $open, $was );
		if ( method_exists( $wpdb, 'flush' ) ) {
			$wpdb->flush();
		}

		if ( $found < self::SWEEP_BATCH ) {
			$state['done'] = 1;
			if ( ! empty( $state['import'] ) || ! empty( $state['push'] ) || ! empty( $state['unknown'] ) ) {
				TCGiant_Sync_Logger::log( sprintf(
					'Who made each eBay listing, for the products linked before 3.28.8: %1$d judged imported from eBay, whose Updates now leave the listing\'s own category, condition, business policies and location as they are on eBay; %2$d judged pushed from WooCommerce, which go on being sent the shop\'s defaults; %3$d that what the shop holds cannot tell, each read from eBay once, at its next Update, before anything of the shop\'s defaults is sent. A product\'s eBay tab can say otherwise for it.',
					(int) ( $state['import'] ?? 0 ),
					(int) ( $state['push'] ?? 0 ),
					(int) ( $state['unknown'] ?? 0 )
				) );
			}
		}

		update_option( self::SWEEP_OPTION, $state );
	}

	/**
	 * The facts judge_before_log() reads, for one product, from what the
	 * pass's queries selected.
	 *
	 * @param int    $id       Product.
	 * @param string $made_gmt Its post_date_gmt.
	 * @param array  $meta     sweep_meta()'s values for it.
	 * @param array  $mids     Their meta IDs.
	 * @param array  $table    Its Listings table row: ebay_item_id, ebay_start_time.
	 * @return array
	 */
	private static function sweep_facts( $id, $made_gmt, array $meta, array $mids, array $table ) {
		$item_id  = (string) ( $meta['_ebay_item_id'] ?? '' );
		$relisted = false;
		foreach ( self::RELIST_MARKS as $key ) {
			$value    = (string) ( $meta[ $key ] ?? '' );
			$relisted = $relisted || ( '' !== $value && '0' !== $value && 'a:0:{}' !== $value );
		}

		return array(
			'item_id'     => $item_id,
			'last_pushed' => (string) ( $meta['_ebay_export_last_pushed'] ?? '' ),
			'status'      => (string) ( $meta['_ebay_export_status'] ?? '' ),
			'start'       => (string) ( $meta['_ebay_start_time'] ?? '' ),
			'made'        => (string) $made_gmt,
			'table_start' => (string) ( $table['ebay_item_id'] ?? '' ) === $item_id ? (string) ( $table['ebay_start_time'] ?? '' ) : '',
			'sku_first'   => isset( $mids['_ebay_sku'], $mids['_ebay_item_id'] ) && $mids['_ebay_sku'] < $mids['_ebay_item_id'],
			'relisted'    => $relisted,
		);
	}

	/**
	 * These meta of these products, as one query selects them: product ID
	 * => key => the stored value (still serialized), and in $mids each one's
	 * meta ID. Of duplicate rows, the first, as get_post_meta( ..., true )
	 * and so origin() reads it.
	 *
	 * @param int[]    $ids  Products.
	 * @param string[] $keys Meta keys.
	 * @param bool     $logs Only sync logs that can say 'import'.
	 * @param array    $mids Filled: product ID => key => meta ID.
	 * @return array<int,array<string,string>>
	 */
	private static function sweep_meta( array $ids, array $keys, $logs, array &$mids ) {
		if ( ! $ids ) {
			return array();
		}

		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$type = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$args = $keys;
		$only = '';
		if ( $logs ) {
			$only   = " AND meta_value NOT LIKE 'a:20:%%' AND meta_value LIKE %s";
			$args[] = '%' . $wpdb->esc_like( 'New product imported. All fields synced from eBay.' ) . '%';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value, meta_id FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ({$type}){$only} ORDER BY meta_id ASC", $args ), ARRAY_A );

		$out = array();
		foreach ( $rows as $i => $row ) {
			$id  = (int) $row['post_id'];
			$key = (string) $row['meta_key'];
			if ( ! isset( $out[ $id ][ $key ] ) ) {
				$out[ $id ][ $key ]  = (string) $row['meta_value'];
				$mids[ $id ][ $key ] = (int) ( $row['meta_id'] ?? 0 );
			}
			unset( $rows[ $i ] );
		}

		return $out;
	}

	/**
	 * The Listings table's listing and start for these products, where the
	 * table is there: product ID => ebay_item_id, ebay_start_time.
	 *
	 * @param int[] $ids Products.
	 * @return array<int,array<string,string>>
	 */
	private static function sweep_table_starts( array $ids ) {
		if ( ! $ids || ! class_exists( 'TCGiant_Sync_DB' ) || ! TCGiant_Sync_DB::table_exists() ) {
			return array();
		}

		global $wpdb;
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$table = TCGiant_Sync_DB::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT product_id, ebay_item_id, ebay_start_time FROM {$table} WHERE product_id IN ({$in})", ARRAY_A );

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['product_id'] ] = array( 'ebay_item_id' => (string) $row['ebay_item_id'], 'ebay_start_time' => (string) $row['ebay_start_time'] );
		}

		return $out;
	}

	/**
	 * Whether this request is an AJAX one - the heartbeat, "Process Queue".
	 *
	 * @return bool
	 */
	private static function doing_ajax() {
		return ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX );
	}

	/**
	 * Keep the pass's verdict for a product, and count it: added where it has
	 * no origin; where it has one - from before passes said who wrote it, or
	 * a pass's - only while that is still so. An 'import' there is never
	 * turned into the shop's, nor taken off: it stays, and is marked the
	 * pass's when the pass agrees. A 'push' there is turned into 'import',
	 * or taken off where nothing tells now, so the first Update reads the
	 * listing.
	 *
	 * @param int         $id      Product.
	 * @param string      $verdict 'import', 'push', or '' when nothing tells.
	 * @param string|null $was     Its origin when selected, or null for none.
	 * @param array       $state   The pass's record.
	 * @return void
	 */
	private static function keep_swept( $id, $verdict, $was, array &$state ) {
		$counted = '' === $verdict ? 'unknown' : $verdict;
		if ( null !== $was ) {
			$by = self::origin_writer( $id );
			if ( '' !== $by && self::BY_SWEEP !== $by ) {
				return;
			}
			// Never an import turned into the shop's, nor into not known:
			// confirmed as it is.
			if ( 'import' === $was && 'import' !== $verdict ) {
				if ( '' === $by ) {
					add_post_meta( $id, self::ORIGIN_BY_META, self::BY_SWEEP, true );
				}
				$state['import'] = (int) ( $state['import'] ?? 0 ) + 1;
				return;
			}
			if ( $verdict === $was && '' !== $verdict ) {
				if ( '' === $by ) {
					add_post_meta( $id, self::ORIGIN_BY_META, self::BY_SWEEP, true );
				}
				$state[ $counted ] = (int) ( $state[ $counted ] ?? 0 ) + 1;
				return;
			}
			delete_post_meta( $id, self::ORIGIN_META );
			if ( '' !== $by ) {
				delete_post_meta( $id, self::ORIGIN_BY_META, self::BY_SWEEP );
			}
		}
		if ( '' === $verdict ) {
			$state['unknown'] = (int) ( $state['unknown'] ?? 0 ) + 1;
			return;
		}
		if ( add_post_meta( $id, self::ORIGIN_META, $verdict, true ) ) {
			add_post_meta( $id, self::ORIGIN_BY_META, self::BY_SWEEP, true );
			$state[ $verdict ] = (int) ( $state[ $verdict ] ?? 0 ) + 1;
		}
	}

	/**
	 * Who wrote a product's origin (ORIGIN_BY_META), as the database has it
	 * now: one query, never the product's whole meta.
	 *
	 * @param int $id Product.
	 * @return string
	 */
	private static function origin_writer( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
			(int) $id,
			self::ORIGIN_BY_META
		) );
	}

	/**
	 * Whether a push sends a product's listing the shop's defaults where the
	 * product sets nothing of its own (decision 15): yes for a product
	 * without a listing and for one the plugin pushed; no for one imported,
	 * which keeps eBay's values. The shop's own choice for the product
	 * (FOLLOW_META) comes first.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function follows_defaults( $product_id ) {
		$choice = (string) get_post_meta( (int) $product_id, self::FOLLOW_META, true );
		if ( '1' === $choice ) {
			return true;
		}
		if ( '0' === $choice ) {
			return false;
		}

		return self::origin_follows( self::origin( $product_id ) );
	}

	/**
	 * Whether a product of this origin follows the shop's defaults when the
	 * shop has not said: one with no listing, and one the plugin pushed. Not
	 * one imported, nor one not known yet (UNKNOWN), which is never sent the
	 * shop's defaults for want of knowing.
	 *
	 * @param string $origin origin().
	 * @return bool
	 */
	public static function origin_follows( $origin ) {
		return 'push' === $origin || '' === $origin;
	}

	/**
	 * Whether a product's photos are exactly its listing's on eBay: the
	 * featured image and then the gallery, each one the import made from the
	 * listing's photo in the same place (_tcgiant_source_url), and no other.
	 * A revise of an imported listing then leaves its photos as they are, and
	 * none is uploaded for it.
	 *
	 * Not a photo edited in WordPress's image editor since: the same
	 * attachment and source address, with a new file. The editor keeps the
	 * original's sizes (_wp_attachment_backup_sizes) and names the new file
	 * "-e" and 13 digits. A plugin that replaces a file in place leaves no
	 * such trace, and that edit reaches eBay with the next change to the
	 * product's photos.
	 *
	 * @param int[] $photo_ids The product's photos, featured first.
	 * @param array $live      snapshot().
	 * @return bool
	 */
	public static function photos_match( array $photo_ids, array $live ) {
		$pictures  = isset( $live['pictures'] ) && is_array( $live['pictures'] ) ? array_values( $live['pictures'] ) : array();
		$photo_ids = array_values( array_filter( array_map( 'intval', $photo_ids ) ) );

		if ( ! $pictures || count( $pictures ) !== count( $photo_ids ) ) {
			return false;
		}

		foreach ( $photo_ids as $i => $id ) {
			$source = (string) get_post_meta( $id, '_tcgiant_source_url', true );
			if ( '' === $source || $source !== (string) $pictures[ $i ] ) {
				return false;
			}
			$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
			if ( ! empty( $backup ) || preg_match( '/-e\d{13}\.[a-z0-9]+$/i', (string) get_post_meta( $id, '_wp_attached_file', true ) ) ) {
				return false;
			}
		}

		return true;
	}
}
