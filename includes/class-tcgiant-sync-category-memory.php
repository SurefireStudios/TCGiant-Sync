<?php
/**
 * The categories of each of the seller's listings, as eBay last gave them.
 *
 * eBay's list of changes, GetSellerEvents, sends most rows with no category:
 * no PrimaryCategory, no store categories. A shop that imports only some
 * categories ("eBay Standard Categories to Import", or its store categories)
 * had the delta ask GetItem for each such row only to learn its category - and
 * then, mostly, skip it. One shop that imports 2 store categories out of 5,466
 * listings spent about 440 GetItem calls a day that way, for about 2 imports a
 * day, out of a daily call limit its whole fleet shares.
 *
 * The weekly full sync's GetSellerList pages carry every listing's categories,
 * and every GetItem answer does too. This keeps them, by ItemID, with when they
 * were learnt, and asks eBay nothing of its own. The delta looks a bare row up
 * here first: a listing remembered within FRESH_SECONDS whose categories the
 * filter does not take is skipped without its GetItem, exactly as the filter
 * would have skipped it after one. Anything else - not remembered, remembered
 * too long ago, or in a category the filter takes - is asked about as before.
 * It stores categories, not decisions, so a change to the filter needs nothing
 * cleared: the next delta judges what is remembered by the filter as it is
 * then.
 *
 * A listing the seller moves into an imported category is read with its old
 * categories until the memory learns the new ones - at the next full sync, or
 * the next GetItem of it, so within about a week. Every delta row is a changed
 * listing, so the change itself cannot say it was a move.
 *
 * Kept in CHUNKS options, never autoloaded, each holding the listings whose
 * ItemID falls to it as plain text, one line a listing:
 * "ItemID,PrimaryCategory,StoreCategory,StoreCategory2,learnt". About 45 bytes
 * a listing, so 15,000 listings are about 20 KB an option, 650 KB in all. Not
 * one option: every lookup would read all of it, and a shop of 30,000 listings
 * would take it past the 1 MB a memcached item holds. And not arrays of
 * entries, which PHP holds at several times the size, and a request would hold
 * whole. A lookup reads one option and finds its line; a write reads, merges
 * and writes the options the listings fall to, one at a time, so a request
 * never holds more than one of them parsed. A batch of the full sync writes
 * what its pages carried once, at its end, and a delta batch the same; either
 * sooner if it has learnt PENDING_MAX listings. A page's 200 listings touch
 * nearly every option, so writing once a page rewrote all of them each time.
 *
 * Each option is read afresh from the database just before it is merged and
 * written - not from this request's copy, which a batch running for minutes
 * would write back over what other requests had written meanwhile. Two
 * requests merging the same option at the same moment can still lose one's
 * listings: there is no lock. add_option() cannot be one (it writes with ON
 * DUPLICATE KEY UPDATE, so both would be told they had it), and a real one is
 * raw SQL on the options table for something whose loss costs only this: a
 * listing remembered a little older, or not at all - a GetItem, as before.
 *
 * The shop's own pushes change a listing's category: a push that revises,
 * lists or relists one forgets what was remembered of it (forget()).
 *
 * Bounded three ways: a full sync that read every page drops what it did not
 * see (after_full_scan(), allowing SKEW_SECONDS for a server whose clock is a
 * little behind the one that began it); a listing not learnt again within
 * FRESH_SECONDS is of no use and goes when its option is next written; and no
 * option holds more than CHUNK_MAX listings, the ones learnt longest ago going
 * first.
 *
 * Written only while the shop filters by category. A shop that does not has
 * no use for it, and a full sync that finds the filter off deletes it.
 *
 * And which coin parent each coin category sits under (3.28.5,
 * coin_parent()). The coin categories the plugin's list offers are parents on
 * eBay, under which eBay files no listing, so a ticked one matched nothing.
 * eBay's path for a listing's category says its parent, and the full sync's
 * pages and GetItem carry it; what they teach is kept as one small option,
 * category ID => parent, so a listing remembered here by its category ID is
 * still decided without a GetItem.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.28.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Category_Memory class.
 */
class TCGiant_Sync_Category_Memory {

	/** Option name prefix: OPTION_PREFIX . 0 to OPTION_PREFIX . ( CHUNKS - 1 ). */
	const OPTION_PREFIX = 'tcgiant_sync_category_memory_';

	/** Options the listings are spread over, by ItemID (chunk_of()). A power of 2. */
	const CHUNKS = 32;

	/**
	 * How long what was learnt may stand in for a GetItem: 8 days. The full
	 * sync runs weekly, so a listing it reads is learnt again before this
	 * runs out.
	 */
	const FRESH_SECONDS = 691200;

	/**
	 * A GetItem answer that says what the memory already holds, learnt less
	 * than this ago, writes nothing: the delta asks about the same listings
	 * again and again.
	 */
	const REFRESH_SECONDS = 86400;

	/**
	 * Most listings one option holds: 40,000 in all. Past it, the ones learnt
	 * longest ago go first.
	 */
	const CHUNK_MAX = 1250;

	/**
	 * How far ahead of this server's clock a time learnt may be and still
	 * count. A time further ahead - learnt while the clock was wrong - would
	 * stay fresh for ever; it counts as not learnt.
	 */
	const SKEW_SECONDS = 3600;

	/**
	 * Most listings held for one write. A full sync batch can read many pages;
	 * past this many it writes them and goes on, so what it holds stays well
	 * inside the memory guards (about 400 bytes a listing).
	 */
	const PENDING_MAX = 2000;

	/**
	 * The option the coin categories are kept in (3.28.5): category ID =>
	 * the coin parent it sits under (TCGiant_Sync_Catalog::
	 * COIN_PARENT_CATEGORIES). Under OPTION_PREFIX, as the rest.
	 */
	const COIN_LEAVES_OPTION = 'tcgiant_sync_category_memory_coin_leaves';

	/** Most coin categories kept: past it, the ones learnt first go. */
	const COIN_LEAVES_MAX = 2000;

	/**
	 * Now, in Unix seconds. Overridable for tests.
	 *
	 * @var callable|null
	 */
	public static $clock = null;

	/**
	 * Coin categories learnt and not yet written: category ID => parent.
	 *
	 * @var array
	 */
	private static $pending_leaves = array();

	/**
	 * Listings learnt and not yet written: ItemID => array( categories, force ).
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * How many begin() calls are open. While any is, what is learnt waits for
	 * the last end().
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Now, in Unix seconds.
	 *
	 * @return int
	 */
	public static function now() {
		return self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/**
	 * Whether the shop filters its imports by category - the only time the
	 * memory is used, and so the only time it is written.
	 *
	 * The same test as the importer's, on the same settings: custom categories
	 * set, or standard categories ticked.
	 *
	 * @param array|null $settings The plugin's settings; read when omitted.
	 * @return bool
	 */
	public static function in_use( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = class_exists( 'TCGiant_Sync_OAuth' )
				? TCGiant_Sync_OAuth::instance()->get_settings()
				: get_option( 'tcgiant_sync_ebay_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
		}

		return ! empty( $settings['category_ids'] )
			|| ( ! empty( $settings['import_standard_category_ids'] ) && is_array( $settings['import_standard_category_ids'] ) );
	}

	/**
	 * Hold what is learnt until end(), and write it once.
	 *
	 * @return void
	 */
	public static function begin() {
		self::$depth++;
	}

	/**
	 * Write what was learnt since begin(), once the last one open ends.
	 *
	 * @return void
	 */
	public static function end() {
		if ( self::$depth > 0 ) {
			self::$depth--;
		}

		if ( 0 === self::$depth ) {
			self::flush();
		}
	}

	/**
	 * Learn a listing's categories from a GetItem answer the plugin already
	 * has. Written at once unless a begin() is open.
	 *
	 * @param mixed $item GetItem's Item.
	 * @return void
	 */
	public static function note( $item ) {
		if ( ! is_array( $item ) || ! self::in_use() ) {
			return;
		}

		self::add( $item, false );

		if ( 0 === self::$depth || count( self::$pending ) >= self::PENDING_MAX ) {
			self::flush();
		}
	}

	/**
	 * Learn every listing on a page of the full sync, matched or not. Written
	 * with the batch's end() - the scan holds a begin() open for each batch -
	 * or at once outside one, and every PENDING_MAX listings either way. Each
	 * is written learnt now, even when the memory says the same: that is what
	 * keeps it from after_full_scan(). A batch killed before it writes loses
	 * what its pages taught, which the next full sync learns again; until then
	 * those listings get their GetItem, as before.
	 *
	 * @param array $items Listings as GetSellerList sent them.
	 * @return void
	 */
	public static function note_page( array $items ) {
		if ( empty( $items ) || ! self::in_use() ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				self::add( $item, true );
			}
		}

		if ( 0 === self::$depth || count( self::$pending ) >= self::PENDING_MAX ) {
			self::flush();
		}
	}

	/**
	 * Forget what is remembered of listings: the shop has just revised, listed
	 * or relisted them, and a push sends its own PrimaryCategory. Until a
	 * GetItem or the next full sync learns them again, the delta asks about
	 * them, as before.
	 *
	 * @param string[] $item_ids eBay ItemIDs; empty ones are passed over.
	 * @return void
	 */
	public static function forget( array $item_ids ) {
		$by_chunk = array();

		foreach ( $item_ids as $item_id ) {
			$item_id = (string) $item_id;
			if ( self::valid_id( $item_id ) ) {
				unset( self::$pending[ $item_id ] );
				$by_chunk[ self::chunk_of( $item_id ) ][] = $item_id;
			}
		}

		foreach ( $by_chunk as $chunk => $ids ) {
			list( $cats, $when ) = self::read_chunk( $chunk );
			$before = count( $cats );

			foreach ( $ids as $item_id ) {
				unset( $cats[ $item_id ], $when[ $item_id ] );
			}

			if ( count( $cats ) !== $before ) {
				self::write_chunk( $chunk, $cats, $when );
			}
		}
	}

	/**
	 * The categories remembered for listings, where they are fresh.
	 *
	 * @param string[] $item_ids eBay ItemIDs.
	 * @return array ItemID => array( PrimaryCategory, StoreCategory, StoreCategory2 ),
	 *               only for the ones remembered within FRESH_SECONDS.
	 */
	public static function recall( array $item_ids ) {
		$found    = array();
		$by_chunk = array();

		foreach ( $item_ids as $item_id ) {
			$item_id = (string) $item_id;
			if ( self::valid_id( $item_id ) ) {
				$by_chunk[ self::chunk_of( $item_id ) ][] = $item_id;
			}
		}

		$now = self::now();

		foreach ( $by_chunk as $chunk => $ids ) {
			$raw = get_option( self::option_name( $chunk ), '' );
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}

			$raw = "\n" . $raw . "\n";
			foreach ( $ids as $item_id ) {
				$at = strpos( $raw, "\n" . $item_id . ',' );
				if ( false === $at ) {
					continue;
				}

				$line  = substr( $raw, $at + 1, strpos( $raw, "\n", $at + 1 ) - $at - 1 );
				$entry = self::parse_line( $line );
				if ( null !== $entry && self::is_fresh( $entry[2], $now ) ) {
					$found[ $item_id ] = explode( ',', $entry[1] );
				}
			}
			unset( $raw );
		}

		return $found;
	}

	/**
	 * The coin parent a listing's category sits under (3.28.5), for the
	 * import filter: from eBay's path for it, when the answer in hand has
	 * one, or else from what an earlier answer taught. '' when neither says.
	 *
	 * @param mixed $category_id PrimaryCategory.CategoryID.
	 * @param mixed $path        PrimaryCategory.CategoryName, eBay's path.
	 * @return string The parent's ID.
	 */
	public static function coin_parent( $category_id, $path = '' ) {
		$category_id = is_scalar( $category_id ) ? (string) $category_id : '';
		if ( ! self::valid_id( $category_id ) ) {
			return '';
		}

		// eBay's full path under Coins & Paper Money only: a name that is not
		// one leaves it to what was learnt.
		if ( is_string( $path ) && 0 === stripos( $path, 'Coins & Paper Money:' ) ) {
			return TCGiant_Sync_Catalog::coin_parent_in_path( $path );
		}

		if ( isset( self::$pending_leaves[ $category_id ] ) ) {
			return (string) self::$pending_leaves[ $category_id ];
		}

		$leaves = get_option( self::COIN_LEAVES_OPTION, array() );

		return is_array( $leaves ) && isset( $leaves[ $category_id ] ) ? (string) $leaves[ $category_id ] : '';
	}

	/**
	 * Write what was learnt.
	 *
	 * One option at a time: read, merge, tidy, written only if it changed.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( ! empty( self::$pending_leaves ) ) {
			self::write_coin_leaves();
		}

		if ( empty( self::$pending ) ) {
			return;
		}

		$by_chunk = array();
		foreach ( self::$pending as $item_id => $learnt ) {
			$by_chunk[ self::chunk_of( (string) $item_id ) ][ (string) $item_id ] = $learnt;
		}
		self::$pending = array();

		$now = self::now();

		foreach ( $by_chunk as $chunk => $learnt ) {
			list( $cats, $when ) = self::read_chunk( $chunk );
			$changed = false;

			foreach ( $learnt as $item_id => $entry ) {
				list( $categories, $force ) = $entry;

				// The same as is held, learnt lately: nothing to write.
				if ( ! $force && isset( $cats[ $item_id ] ) && $cats[ $item_id ] === $categories
					&& $now - $when[ $item_id ] < self::REFRESH_SECONDS && $when[ $item_id ] <= $now ) {
					continue;
				}

				$cats[ $item_id ] = $categories;
				$when[ $item_id ] = $now;
				$changed          = true;
			}

			if ( self::tidy( $cats, $when, $now ) ) {
				$changed = true;
			}

			if ( $changed ) {
				self::write_chunk( $chunk, $cats, $when );
			}
			unset( $cats, $when );
		}
	}

	/**
	 * After a full sync that read every page: drop each listing it did not see.
	 *
	 * The full sync wrote every listing it read, learnt then
	 * (note_page()), so a listing learnt before it began - by more than
	 * SKEW_SECONDS - is one it never saw: ended, or deleted. One learnt from a
	 * GetItem since it began stays. With the filter off, the memory has no use
	 * and is deleted.
	 *
	 * @param string $started When the full sync began (full_started_at).
	 * @return int Listings dropped.
	 */
	public static function after_full_scan( $started ) {
		if ( ! self::in_use() ) {
			self::forget_all();
			return 0;
		}

		$since = strtotime( (string) $started );
		if ( false === $since || $since <= 0 ) {
			return 0;
		}

		$now     = self::now();
		$dropped = 0;

		for ( $chunk = 0; $chunk < self::CHUNKS; $chunk++ ) {
			list( $cats, $when ) = self::read_chunk( $chunk );
			if ( empty( $cats ) ) {
				continue;
			}

			// Allowing for a server a little behind the one that began the
			// sync: a listing learnt just before it began, and not seen since,
			// is still true of a listing that has ended.
			$before = count( $cats );
			foreach ( $when as $item_id => $at ) {
				if ( $at < $since - self::SKEW_SECONDS ) {
					unset( $cats[ $item_id ], $when[ $item_id ] );
				}
			}
			self::tidy( $cats, $when, $now );

			if ( count( $cats ) !== $before ) {
				$dropped += $before - count( $cats );
				self::write_chunk( $chunk, $cats, $when );
			}
			unset( $cats, $when );
		}

		return $dropped;
	}

	/**
	 * Delete everything remembered, and anything learnt and not yet written.
	 *
	 * @return void
	 */
	public static function forget_all() {
		self::$pending        = array();
		self::$pending_leaves = array();

		for ( $chunk = 0; $chunk < self::CHUNKS; $chunk++ ) {
			delete_option( self::option_name( $chunk ) );
		}
		delete_option( self::COIN_LEAVES_OPTION );
	}

	/**
	 * The option a chunk is kept in.
	 *
	 * @param int $chunk 0 to CHUNKS - 1.
	 * @return string
	 */
	public static function option_name( $chunk ) {
		return self::OPTION_PREFIX . (int) $chunk;
	}

	/**
	 * Which chunk a listing is kept in.
	 *
	 * @param string $item_id eBay ItemID.
	 * @return int 0 to CHUNKS - 1.
	 */
	public static function chunk_of( $item_id ) {
		return crc32( (string) $item_id ) & ( self::CHUNKS - 1 );
	}

	/**
	 * Hold a listing's categories for the next write, when they can be kept.
	 *
	 * @param array $item  eBay's listing.
	 * @param bool  $force Write it learnt now even if the memory says the same.
	 * @return void
	 */
	private static function add( array $item, $force ) {
		// The coin parent its category sits under, when eBay's path says one
		// the memory does not hold yet.
		$category = $item['PrimaryCategory']['CategoryID'] ?? '';
		$parent   = self::coin_parent( $category, $item['PrimaryCategory']['CategoryName'] ?? '' );
		if ( '' !== $parent && $parent !== self::coin_parent( $category ) ) {
			self::$pending_leaves[ (string) $category ] = $parent;
		}

		$item_id    = isset( $item['ItemID'] ) && is_scalar( $item['ItemID'] ) ? (string) $item['ItemID'] : '';
		$categories = self::categories_of( $item );

		if ( ! self::valid_id( $item_id ) || '' === $categories ) {
			return;
		}

		self::$pending[ $item_id ] = array( $categories, (bool) $force );
	}

	/**
	 * A listing's three categories as kept: "PrimaryCategory,StoreCategory,
	 * StoreCategory2", each exactly as eBay sent it.
	 *
	 * '' - not kept - when any of them is not plain digits, so what is kept is
	 * always what the filter would have judged; and when all three are empty,
	 * since the delta imports a listing eBay gives no category for, and that is
	 * a GetItem's to decide.
	 *
	 * @param array $item eBay's listing.
	 * @return string
	 */
	private static function categories_of( array $item ) {
		$values = array(
			$item['PrimaryCategory']['CategoryID'] ?? '',
			$item['Storefront']['StoreCategoryID'] ?? '',
			$item['Storefront']['StoreCategory2ID'] ?? '',
		);

		foreach ( $values as $n => $value ) {
			if ( ! is_scalar( $value ) || 1 !== preg_match( '/^[0-9]{0,19}$/', (string) $value ) ) {
				return '';
			}
			$values[ $n ] = (string) $value;
		}

		if ( '' === $values[0] && '' === $values[1] && '' === $values[2] ) {
			return '';
		}

		return implode( ',', $values );
	}

	/**
	 * Whether an ItemID can be kept: eBay's are digits.
	 *
	 * @param string $item_id eBay ItemID.
	 * @return bool
	 */
	private static function valid_id( $item_id ) {
		return 1 === preg_match( '/^[0-9]{1,19}$/', (string) $item_id );
	}

	/**
	 * One line of a chunk.
	 *
	 * @param string $line "ItemID,PrimaryCategory,StoreCategory,StoreCategory2,learnt".
	 * @return array|null array( ItemID, categories, learnt ), or null for a line that is not one.
	 */
	private static function parse_line( $line ) {
		// Never three empty categories: categories_of() keeps none such, and
		// the delta imports a listing eBay gives none for.
		if ( 1 !== preg_match( '/^([0-9]{1,19}),([0-9]{0,19},[0-9]{0,19},[0-9]{0,19}),([0-9]{1,12})$/', (string) $line, $m ) || ',,' === $m[2] ) {
			return null;
		}

		return array( $m[1], $m[2], (int) $m[3] );
	}

	/**
	 * Whether a time learnt is recent enough to stand in for a GetItem.
	 *
	 * @param int $learnt Unix time.
	 * @param int $now    Unix time.
	 * @return bool
	 */
	private static function is_fresh( $learnt, $now ) {
		return $learnt <= $now + self::SKEW_SECONDS && $now - $learnt <= self::FRESH_SECONDS;
	}

	/**
	 * A chunk, parsed, for a write: what is not a line of one is dropped.
	 *
	 * Read afresh from the database, not from this request's copy of it, so
	 * the write merges what other requests have written since this one first
	 * read it.
	 *
	 * @param int $chunk 0 to CHUNKS - 1.
	 * @return array array( ItemID => categories, ItemID => learnt ).
	 */
	private static function read_chunk( $chunk ) {
		$cats = array();
		$when = array();
		$name = self::option_name( $chunk );
		self::drop_cached( $name );
		$raw  = get_option( $name, '' );

		if ( is_string( $raw ) && '' !== $raw ) {
			foreach ( explode( "\n", $raw ) as $line ) {
				$entry = self::parse_line( $line );
				if ( null !== $entry ) {
					$cats[ $entry[0] ] = $entry[1];
					$when[ $entry[0] ] = $entry[2];
				}
			}
		}

		return array( $cats, $when );
	}

	/**
	 * Drop this request's copy of an option, so the next get_option() reads
	 * the database. WordPress keeps the options it found missing too.
	 *
	 * @param string $name Option.
	 * @return void
	 */
	private static function drop_cached( $name ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( $name, 'options' );

		if ( function_exists( 'wp_cache_get' ) && function_exists( 'wp_cache_set' ) ) {
			$missing = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $missing ) && isset( $missing[ $name ] ) ) {
				unset( $missing[ $name ] );
				wp_cache_set( 'notoptions', $missing, 'options' );
			}
		}
	}

	/**
	 * Drop what is no longer fresh, then the oldest past CHUNK_MAX.
	 *
	 * @param array $cats ItemID => categories.
	 * @param array $when ItemID => learnt.
	 * @param int   $now  Unix time.
	 * @return bool Whether anything was dropped.
	 */
	private static function tidy( array &$cats, array &$when, $now ) {
		$before = count( $cats );

		foreach ( $when as $item_id => $at ) {
			if ( ! self::is_fresh( $at, $now ) ) {
				unset( $cats[ $item_id ], $when[ $item_id ] );
			}
		}

		if ( count( $when ) > self::CHUNK_MAX ) {
			asort( $when, SORT_NUMERIC );
			foreach ( array_slice( array_keys( $when ), 0, count( $when ) - self::CHUNK_MAX ) as $item_id ) {
				unset( $cats[ $item_id ], $when[ $item_id ] );
			}
		}

		return count( $cats ) !== $before;
	}

	/**
	 * Write the coin categories learnt, onto the option as the database
	 * holds it now, keeping the last COIN_LEAVES_MAX learnt.
	 *
	 * @return void
	 */
	private static function write_coin_leaves() {
		self::drop_cached( self::COIN_LEAVES_OPTION );
		$leaves = get_option( self::COIN_LEAVES_OPTION, array() );
		$leaves = is_array( $leaves ) ? $leaves : array();

		foreach ( self::$pending_leaves as $category => $parent ) {
			$leaves[ $category ] = $parent;
		}
		self::$pending_leaves = array();

		if ( count( $leaves ) > self::COIN_LEAVES_MAX ) {
			$leaves = array_slice( $leaves, -self::COIN_LEAVES_MAX, null, true );
		}

		update_option( self::COIN_LEAVES_OPTION, $leaves, false );
	}

	/**
	 * Write a chunk, or delete it when it holds nothing.
	 *
	 * @param int   $chunk 0 to CHUNKS - 1.
	 * @param array $cats  ItemID => categories.
	 * @param array $when  ItemID => learnt.
	 * @return void
	 */
	private static function write_chunk( $chunk, array $cats, array $when ) {
		$name = self::option_name( $chunk );

		if ( empty( $cats ) ) {
			delete_option( $name );
			return;
		}

		$lines = array();
		foreach ( $cats as $item_id => $categories ) {
			$lines[] = $item_id . ',' . $categories . ',' . (int) $when[ $item_id ];
		}

		update_option( $name, implode( "\n", $lines ), false );
	}
}
