<?php
/**
 * A shop's own photos, as eBay sends them back (3.30.4).
 *
 * A push puts a product's photos on eBay, and eBay gives each a picture number
 * of its own. With "Host listing images on eBay?" on, the push uploads each
 * photo (UploadSiteHostedPictures) and eBay answers with the copy's address,
 * which carries the number; with it off, the push sends the photos' addresses
 * on the website and eBay makes copies of them itself, under numbers the push
 * never sees - GetItem gives eBay's copies (PictureDetailsType: a self-hosted
 * picture is copied to eBay Picture Services and "the PictureDetails.
 * PictureURL value returned by GetItem will be an EPS URL"; whether the first
 * picture's copy is among them, or only its original in ExternalPictureURL,
 * the page does not say plainly - see learn_by_place()).
 *
 * Until 3.30.4 the image batch knew a photo only by the address it was
 * downloaded from (_tcgiant_source_url). The listing a push made showed
 * addresses it had never downloaded, so the next sync downloaded every photo
 * again as a new one. With "Overwrite Images" off the copies went into the
 * gallery behind the photos they copy, the main photo's copy among them; with
 * it on they replaced the main photo and the gallery, the main photo becoming
 * whatever eBay showed first. The copies had no eBay copy of their own, so the
 * next push uploaded them, and the sync after brought those back too.
 *
 * Now:
 *
 * - every number a photo was put on eBay as is kept on it (NUMBER_META, one
 *   row each, found by meta_value - through the plugin's own (meta_key,
 *   meta_value) index on post meta where the database let it be added
 *   (TCGiant_Sync_DB::maybe_add_postmeta_index()), and otherwise by reading
 *   the rows of that key, as the older lookup by _tcgiant_source_url is):
 *   from the upload (TCGiant_Sync_Exporter::eps_picture_url()), and from the
 *   cached copy of an upload made before 3.30.4 (_tcgiant_eps_url, read on
 *   its own and copied over once, in batches, by run_heal()); from a listing
 *   whose photos a push sent from the website, by their places, the first
 *   time the import reads it after that push (learn_by_place()) - marked as
 *   learnt so (PLACE), and the photo's only for the product that shows it;
 *   and, when the repair shows a photo again in place of a copy of it, or
 *   Image Cleanup removes a copy, from the copy (carry_numbers());
 * - a push records what it sent (PUSHED_META): the listing, the photos in the
 *   order sent, their addresses, and the photos the product showed then;
 * - the image batch takes an eBay address that is one of these numbers, or
 *   the number of the address a photo was downloaded from, for that photo
 *   (own_photo()): whatever size or form the address is in, the product's own
 *   photos first, and a copy found elsewhere as the photo it copies;
 * - a downloaded copy of a photo this site put on eBay - its source's number
 *   is one another photo was put on eBay as - is that photo's copy
 *   (original_of()). The pass run_heal() shows a pushed product's own photo
 *   again in its copy's place, once, and Image Cleanup's check counts the
 *   copies nothing shows any more with the leftovers it removes
 *   (copies_among());
 * - a listing of a product pushed lately whose every picture may be a copy
 *   eBay made of a push from the website that taught nothing - numbers
 *   nothing here knows, no more of them than the photos the product shows -
 *   is held, not downloaded, and those pictures are never downloaded for it
 *   (may_hold(), hold()), unless the shop asks for them with an images-only
 *   sync (release()).
 *
 * Nothing here asks eBay anything, and nothing here deletes or changes a
 * photo's file or its record: run_heal() writes only a product's main photo
 * and gallery lists, and adds number rows.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.30.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Own_Photos class.
 */
class TCGiant_Sync_Own_Photos {

	/**
	 * On a photo: an eBay picture number it is on eBay as, one row each. A
	 * push's upload, or a copy eBay made of it from the website's address.
	 */
	const NUMBER_META = '_tcgiant_ebay_number';

	/**
	 * Before a number kept on a photo that was learnt by its place in a
	 * listing, not from an upload's answer (remember()).
	 */
	const PLACE = 'place:';

	/**
	 * On a product: what its last push that sent photos sent - 'item' (the
	 * listing), 'ids' (the photos, in the order sent), 'urls' (their
	 * addresses, as sent), 'shown' (the photos the product showed then, main
	 * photo first), 'time', 'read' once the import has read the listing after
	 * it, and 'learnt', how many places that read taught (learn_by_place()).
	 * Or, from a revise that kept the listing's own photos (3.28.8), 'item',
	 * 'time' and 'kept': the listing's photos are eBay's own.
	 */
	const PUSHED_META = '_tcgiant_pushed_photos';

	/**
	 * The pass over products damaged before 3.30.4 (run_heal()): its Action
	 * Scheduler hook and group, its record, and the batch under way.
	 */
	const HEAL_HOOK        = 'tcgiant_sync_own_photos';
	const HEAL_GROUP       = 'tcgiant_sync_own_photos';
	const HEAL_OPTION      = 'tcgiant_sync_own_photos_heal';
	const HEAL_OPEN_OPTION = 'tcgiant_sync_own_photos_heal_open';

	/**
	 * On a product whose listing's pictures were held, not downloaded
	 * (may_hold(), hold()): 'at', when, and 'numbers', the pictures held,
	 * which are never downloaded for it afterwards. Image Cleanup names the
	 * products holding it (held_products()).
	 */
	const HELD_META = '_tcgiant_photos_held';

	/**
	 * How long after a push a listing showing only pictures nothing here
	 * knows is held rather than downloaded (may_hold()): long enough for the
	 * import to have read every listing a push changed, 2026-11-03's among
	 * them. Past it, such a listing is photos changed on eBay, and comes in.
	 */
	const HOLD_DAYS = 30;

	/**
	 * How long after a push the copies a sync downloaded of a push from the
	 * website are looked for (copies_shown()): the next sync's, within days.
	 */
	const COPIES_DAYS = 7;

	/**
	 * On a product whose held pictures the shop asked for with an
	 * images-only sync (release()): the listing they were held on, which is
	 * no longer held. Cleared by its next push.
	 */
	const RELEASED_META = '_tcgiant_photos_released';

	/**
	 * Holds the pass back for ten minutes between admin pages.
	 */
	const HEAL_TICK = 'tcgiant_own_photos_tick';

	/**
	 * Products one batch of the pass looks at, and cached copies' rows one
	 * batch of its first part reads.
	 */
	const HEAL_BATCH    = 100;
	const NUMBERS_BATCH = 500;

	/**
	 * Most products the pass keeps in each of its lists, which Image Cleanup
	 * shows and the log names, and most products the held list keeps: a shop
	 * with more is told of the first 500 of each.
	 */
	const LIST_KEEP = 500;

	/**
	 * How many copies of copies are followed back to the photo itself.
	 */
	const CHAIN_MAX = 10;

	/**
	 * How long the pass waits for the image batch to finish a product it is
	 * working on before it looks at that product again, and how many times.
	 */
	const DEFER_SECONDS = 300;
	const DEFER_ROUNDS  = 6;

	/**
	 * eBay's number for a picture, from its address on eBay's photo servers:
	 * /images/g/<number>/s-l1600.jpg, or /z/<number>/$_57.JPG in the older form
	 * the Trading API still sends - as TCGiant_Sync_Image_Localizer::
	 * photo_file_name() reads it. Whatever the size asked for, and with or
	 * without a query string, the number is the picture.
	 *
	 * @param string $url An address.
	 * @return string The number as eBay writes it, or '' for an address that is not on eBay's photo servers or carries none.
	 */
	public static function number( $url ) {
		$url  = (string) $url;
		$host = preg_match( '#^(?:https?:)?//([^/?\#:@]+)#i', $url, $h ) ? strtolower( $h[1] ) : '';
		if ( 'ebayimg.com' !== $host && '.ebayimg.com' !== substr( $host, -12 ) ) {
			return '';
		}

		return preg_match( '#/(?:g|z)/([A-Za-z0-9~_-]{6,40})/#', $url, $m ) ? $m[1] : '';
	}

	/**
	 * The numbers a photo is on eBay as: those kept on it, learnt by place or
	 * not, and its cached upload's (_tcgiant_eps_url), which an upload made
	 * before 3.30.4 is known by until run_heal() has copied it over.
	 *
	 * With a product, a number learnt by place counts only when the photo is
	 * one that product's push record sent or showed: the product whose
	 * listing taught it, not another that shares the photo.
	 *
	 * @param int $attachment_id Photo.
	 * @param int $product_id    The product asking, or 0 for any.
	 * @return string[]
	 */
	public static function numbers_of( $attachment_id, $product_id = 0 ) {
		$numbers = array();
		$placed  = null;
		foreach ( (array) get_post_meta( (int) $attachment_id, self::NUMBER_META, false ) as $value ) {
			$value = (string) $value;
			if ( 0 === strpos( $value, self::PLACE ) ) {
				if ( null === $placed ) {
					$placed = $product_id < 1 || self::recorded( $product_id, $attachment_id );
				}
				if ( ! $placed ) {
					continue;
				}
				$value = substr( $value, strlen( self::PLACE ) );
			}
			$numbers[] = $value;
		}
		$cached = self::number( (string) get_post_meta( (int) $attachment_id, '_tcgiant_eps_url', true ) );
		if ( '' !== $cached ) {
			$numbers[] = $cached;
		}

		return array_values( array_unique( array_filter( $numbers, function ( $number ) {
			return '' !== $number;
		} ) ) );
	}

	/**
	 * Whether a product's push record sent or showed a photo.
	 *
	 * @param int $product_id Product.
	 * @param int $photo      Photo.
	 * @return bool
	 */
	private static function recorded( $product_id, $photo ) {
		$record = get_post_meta( (int) $product_id, self::PUSHED_META, true );

		return is_array( $record ) && ( in_array( (int) $photo, array_map( 'intval', (array) ( $record['ids'] ?? array() ) ), true ) || in_array( (int) $photo, array_map( 'intval', (array) ( $record['shown'] ?? array() ) ), true ) );
	}

	/**
	 * Keep a number on a photo, once.
	 *
	 * A number learnt by place (learn_by_place()) is kept marked as such
	 * (PLACE): it is the photo's for the product that shows the photo, and for
	 * nothing else - not another product's listing, not the pass, not Image
	 * Cleanup. Only an upload's answer says for certain which photo a number
	 * is; a place could have been changed on eBay before it was read.
	 *
	 * @param int    $attachment_id Photo.
	 * @param string $url           Its copy's address on eBay, or the number itself.
	 * @param bool   $by_place      Learnt by its place in a listing.
	 * @return bool Whether it was new to the photo.
	 */
	public static function remember( $attachment_id, $url, $by_place = false ) {
		$attachment_id = (int) $attachment_id;
		$url           = (string) $url;
		$number        = false !== strpos( $url, '/' ) ? self::number( $url ) : $url;

		if ( $attachment_id < 1 || ! preg_match( '/^[A-Za-z0-9~_-]{6,40}$/', $number ) ) {
			return false;
		}

		$have = array_map( 'strval', (array) get_post_meta( $attachment_id, self::NUMBER_META, false ) );
		if ( in_array( $number, $have, true ) || ( $by_place && in_array( self::PLACE . $number, $have, true ) ) ) {
			return false;
		}

		add_post_meta( $attachment_id, self::NUMBER_META, ( $by_place ? self::PLACE : '' ) . $number );

		return true;
	}

	/**
	 * Record what a push sent, once eBay has taken it
	 * (TCGiant_Sync_Exporter::record_listing_made()). A push that sent no
	 * photos because one would have been left out records nothing, and the
	 * listing's photos are still the last record's. A revise that kept the
	 * listing's own photos (3.28.8) records that they are eBay's own
	 * ('kept'): the product follows eBay's photos as an imported one does
	 * (follows_ebay()), and nothing of its listing is held.
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    The listing.
	 * @param array  $sent       'ids', 'urls' and 'shown', from the build; or
	 *                           'kept'.
	 * @return void
	 */
	public static function record_push( $product_id, $item_id, array $sent ) {
		$product_id = (int) $product_id;

		if ( ! empty( $sent['kept'] ) ) {
			update_post_meta( $product_id, self::PUSHED_META, array(
				'item'   => (string) $item_id,
				'ids'    => array(),
				'urls'   => array(),
				'shown'  => array(),
				'time'   => time(),
				'read'   => time(),
				'learnt' => 0,
				'kept'   => 1,
			) );
			delete_post_meta( $product_id, self::HELD_META );
			delete_post_meta( $product_id, self::RELEASED_META );
			return;
		}

		$ids  = array_values( array_map( 'intval', (array) ( $sent['ids'] ?? array() ) ) );
		$urls = array_values( array_map( 'strval', (array) ( $sent['urls'] ?? array() ) ) );
		if ( ! $ids || count( $ids ) !== count( $urls ) ) {
			return;
		}

		update_post_meta( $product_id, self::PUSHED_META, array(
			'item'   => (string) $item_id,
			'ids'    => $ids,
			'urls'   => $urls,
			'shown'  => self::id_list( (array) ( $sent['shown'] ?? array() ) ),
			'time'   => time(),
			'read'   => 0,
			'learnt' => 0,
		) );

		// Pushed again: its listing's pictures are this push's now. No
		// longer one held (may_hold()), nor released.
		delete_post_meta( $product_id, self::HELD_META );
		delete_post_meta( $product_id, self::RELEASED_META );
	}

	/**
	 * Record what a relist is about to send, before eBay has answered it
	 * (3.32.1 fix round 2, TCGiant_Sync_Exporter::relist_ended_listing()):
	 * as record_push() records a push, but already spent, as a record whose
	 * first read has been. It makes the product one this site pushed, so the
	 * next import holds the pictures of its listing it does not know
	 * (may_hold()) rather than download eBay's copies of the photos sent; and
	 * it can never teach a place (learn_by_place()). Nothing says eBay made
	 * the relist yet: the next read may be of the old listing, still showing
	 * the item that sold, whose pictures would be learnt as the photos sent.
	 * The held pictures and the released mark stay as they are. Once eBay
	 * has answered, the relist records the push itself, unread, against the
	 * new listing (record_push()).
	 *
	 * @param int    $product_id Product.
	 * @param string $item_id    The listing being relisted.
	 * @param array  $sent       'ids', 'urls' and 'shown', from the build.
	 * @return void
	 */
	public static function record_unanswered( $product_id, $item_id, array $sent ) {
		$ids  = array_values( array_map( 'intval', (array) ( $sent['ids'] ?? array() ) ) );
		$urls = array_values( array_map( 'strval', (array) ( $sent['urls'] ?? array() ) ) );
		if ( ! empty( $sent['kept'] ) || ! $ids || count( $ids ) !== count( $urls ) ) {
			return;
		}

		update_post_meta( (int) $product_id, self::PUSHED_META, array(
			'item'   => (string) $item_id,
			'ids'    => $ids,
			'urls'   => $urls,
			'shown'  => self::id_list( (array) ( $sent['shown'] ?? array() ) ),
			'time'   => time(),
			'read'   => time(),
			'learnt' => 0,
		) );
	}

	/**
	 * A product's push record as it is now, for put_back().
	 *
	 * @param int $product_id Product.
	 * @return mixed The record, or '' when it has none.
	 */
	public static function record_state( $product_id ) {
		return get_post_meta( (int) $product_id, self::PUSHED_META, true );
	}

	/**
	 * Put back the push record record_state() read: a relist recorded what
	 * it was about to send before eBay answered (record_unanswered()), and
	 * eBay, or the site, then plainly refused it, so the listing still shows
	 * what it showed (3.32.1 fix round 1).
	 *
	 * @param int   $product_id Product.
	 * @param mixed $state      record_state()'s answer.
	 * @return void
	 */
	public static function put_back( $product_id, $state ) {
		if ( is_array( $state ) && $state ) {
			update_post_meta( (int) $product_id, self::PUSHED_META, $state );
		} else {
			delete_post_meta( (int) $product_id, self::PUSHED_META );
		}
	}

	/**
	 * How a photo is on eBay as a number (3.32.1 fix round 1): 'upload', one
	 * an upload of it was given (kept on it, or its cached copy's); 'place',
	 * one learnt by its place in this product's listing (numbers_of()'s rule);
	 * 'source', the number of the picture it was downloaded from; '' none.
	 *
	 * @param int    $attachment_id Photo.
	 * @param int    $product_id    The product asking.
	 * @param string $number        eBay's number.
	 * @return string
	 */
	public static function kind_of( $attachment_id, $product_id, $number ) {
		$number = (string) $number;
		if ( '' === $number ) {
			return '';
		}

		$rows = array_map( 'strval', (array) get_post_meta( (int) $attachment_id, self::NUMBER_META, false ) );
		if ( in_array( $number, $rows, true ) || self::number( (string) get_post_meta( (int) $attachment_id, '_tcgiant_eps_url', true ) ) === $number ) {
			return 'upload';
		}
		if ( in_array( self::PLACE . $number, $rows, true ) && ( (int) $product_id < 1 || self::recorded( $product_id, $attachment_id ) ) ) {
			return 'place';
		}
		if ( self::number( (string) get_post_meta( (int) $attachment_id, '_tcgiant_source_url', true ) ) === $number ) {
			return 'source';
		}

		return '';
	}

	/**
	 * Whether a product's listing shows eBay's own photos, as the import
	 * left them: its last push to this listing was a revise that kept them
	 * (record_push()). Such a product follows eBay's photos, "Overwrite
	 * Images" included, as one never pushed does.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function follows_ebay( $product_id ) {
		$record = get_post_meta( (int) $product_id, self::PUSHED_META, true );

		return is_array( $record ) && ! empty( $record['kept'] ) && (string) ( $record['item'] ?? '' ) === (string) get_post_meta( (int) $product_id, '_ebay_item_id', true );
	}

	/**
	 * When the product was last pushed, as a timestamp: its last push eBay
	 * answered (_ebay_export_last_pushed, the site's own time), or its last
	 * recorded one, whichever is later. 0 when never.
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	private static function pushed_at( $product_id ) {
		$at    = 0;
		$local = trim( (string) get_post_meta( (int) $product_id, '_ebay_export_last_pushed', true ) );
		if ( '' !== $local ) {
			$gmt = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $local ) : $local;
			$at  = is_string( $gmt ) && '' !== $gmt ? (int) strtotime( $gmt . ' UTC' ) : 0;
		}

		$record = get_post_meta( (int) $product_id, self::PUSHED_META, true );

		return max( $at, is_array( $record ) ? (int) ( $record['time'] ?? 0 ) : 0 );
	}

	/**
	 * Whether a push of the shop's has sent a product to eBay: a push that
	 * eBay answered (_ebay_export_last_pushed), or one that recorded the
	 * photos it sent.
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function pushed( $product_id ) {
		return '' !== (string) get_post_meta( (int) $product_id, '_ebay_export_last_pushed', true )
			|| is_array( get_post_meta( (int) $product_id, self::PUSHED_META, true ) );
	}

	/**
	 * A list of photo IDs as WooCommerce reads one: positive numbers, each
	 * once, in order.
	 *
	 * @param array $ids IDs, or strings of them.
	 * @return int[]
	 */
	private static function id_list( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * A product's main photo, then its gallery, as it shows them now: each
	 * once, as WooCommerce shows them.
	 *
	 * @param int $product_id Product.
	 * @return int[]
	 */
	public static function shown( $product_id ) {
		$gallery = (string) get_post_meta( (int) $product_id, '_product_image_gallery', true );

		return self::id_list( array_merge( array( (int) get_post_thumbnail_id( (int) $product_id ) ), '' === $gallery ? array() : explode( ',', $gallery ) ) );
	}

	/**
	 * The listing's own pictures, in eBay's order, from what the import hands
	 * the image batch: addresses, or [ address, variation ] - a variation's
	 * picture is not among those a push sends.
	 *
	 * @param array $entries The import's entries.
	 * @return string[] Entry index => address.
	 */
	private static function listing_pictures( array $entries ) {
		$places = array();
		foreach ( array_values( $entries ) as $index => $entry ) {
			if ( is_array( $entry ) ) {
				$variation = $entry[1] ?? false;
				if ( $variation && '__retry' !== $variation ) {
					continue;
				}
				$entry = $entry[0] ?? '';
			}
			if ( '' !== (string) $entry ) {
				$places[ $index ] = (string) $entry;
			}
		}

		return $places;
	}

	/**
	 * The numbers of a listing's pictures, in order ('' for one with none).
	 *
	 * @param string[] $places The listing's pictures (listing_pictures()).
	 * @return string[]
	 */
	private static function numbers_in( array $places ) {
		return array_values( array_map( array( __CLASS__, 'number' ), $places ) );
	}

	/**
	 * The photo of the product's own an eBay address is, if any.
	 *
	 * First among the photos the product shows - its main photo, its gallery
	 * and its variations' - one put on eBay as that number, by an upload or
	 * learnt by place, then one downloaded from it; then any photo on the site
	 * an upload put on eBay as it, or the photo that one is a copy of
	 * (root_of()). So the shop's own photo comes
	 * before a copy the old sync downloaded of it, a copy the product still
	 * shows is used where it is (run_heal() swaps those round), and a copy it
	 * no longer shows is never taken back. Never an eBay stand-in, a photo
	 * whose removal was started and given up on, or - from elsewhere - one
	 * waiting to be removed (TCGiant_Sync_Image_Localizer::
	 * find_existing_local_attachment()'s own rules).
	 *
	 * @param int    $product_id Product.
	 * @param string $url        The address eBay gave.
	 * @return int Attachment ID, or 0.
	 */
	public static function own_photo( $product_id, $url ) {
		$number = self::number( $url );
		if ( '' === $number ) {
			return 0;
		}

		$shown      = self::shown( $product_id );
		$variations = get_posts( array(
			'post_type'   => 'product_variation',
			'post_parent' => (int) $product_id,
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => -1,
		) );
		foreach ( (array) $variations as $variation_id ) {
			$shown[] = (int) get_post_thumbnail_id( (int) $variation_id );
		}

		$downloaded = 0;
		foreach ( array_unique( array_filter( $shown ) ) as $id ) {
			if ( get_post_meta( $id, '_tcgiant_external_url', true ) || get_post_meta( $id, '_tcgiant_delete_failed', true ) ) {
				continue;
			}
			if ( in_array( $number, self::numbers_of( $id, $product_id ), true ) ) {
				return (int) $id;
			}
			if ( ! $downloaded && self::number( (string) get_post_meta( $id, '_tcgiant_source_url', true ) ) === $number ) {
				$downloaded = (int) $id;
			}
		}

		if ( $downloaded ) {
			return $downloaded;
		}

		$found = self::uploaded_as( $number );

		return $found > 0 ? self::root_of( $found ) : 0;
	}

	/**
	 * The photo on the site that was put on eBay as a number.
	 *
	 * @param string $number  eBay's number.
	 * @param int    $besides A photo that does not count.
	 * @return int Attachment ID, or 0.
	 */
	private static function uploaded_as( $number, $besides = 0 ) {
		$found = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::NUMBER_META,
					'value' => (string) $number,
				),
				array(
					'key'     => '_tcgiant_external_url',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_tcgiant_delete_failed',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_tcgiant_delete_after',
					'compare' => 'NOT EXISTS',
				),
			),
			'fields'      => 'ids',
			'numberposts' => 2,
		) );

		foreach ( (array) $found as $id ) {
			if ( (int) $id !== (int) $besides ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Learn the numbers of a listing whose pictures carry numbers this site
	 * has never seen - one a push sent from the website, which eBay copied
	 * under numbers of its own - by their places, from what the product's
	 * last push sent. Asked by the import with every listing it reads
	 * (TCGiant_Sync_Image_Localizer::set_external_images()), and acting once
	 * for each push: the first read of the listing after it. After that, the
	 * numbers learnt are matched as any others, and a picture replaced or
	 * moved on eBay later is a new picture or a known one, never a place.
	 *
	 * A read whose pictures are those the import already holds for the
	 * product is the listing as it was before the push - a page read before
	 * it, saved after - and spends nothing: the record waits for the read
	 * that shows the push. Any other read spends it, and notes how many
	 * places it taught ('learnt'), which may_hold() goes by.
	 *
	 * That read teaches only while nothing could have moved the places: the
	 * listing is the one the push sent its photos to, it shows exactly as many
	 * pictures as the push sent, the product shows the photos it showed then,
	 * in the same order, every picture carries a number, no photo sent is
	 * gone or an eBay stand-in, a picture sent as eBay's own address is still
	 * that number, and no picture is a number known on any photo but the one
	 * in its place, of this product or any other. Otherwise nothing is
	 * learnt, and a listing showing only pictures nothing knows is held, not
	 * downloaded (may_hold()).
	 *
	 * GetItem's two possible shapes for a listing sent from the website:
	 * - one picture each: the read learns them, and nothing is downloaded,
	 *   then or later;
	 * - one fewer (the first picture's original only in ExternalPictureURL,
	 *   which the import does not read): the counts differ, nothing is
	 *   learnt, and the listing is held - nothing downloaded either. A
	 *   picture added on eBay before that read makes the counts equal, and
	 *   then each place is taken one off, for this product alone.
	 *
	 * What is learnt is kept marked so (PLACE), the photos' for this product
	 * alone: a picture replaced in Seller Hub before that first read, which
	 * nothing can tell, is taken for the photo in its place here, and never
	 * handed to another product.
	 *
	 * @param int   $product_id Product.
	 * @param array $entries    The listing's photos as the import hands them
	 *                          over: addresses, or [ address, variation ].
	 * @return int[] Entry index => attachment ID, for the places learnt; none
	 *               when nothing was.
	 */
	public static function learn_by_place( $product_id, array $entries ) {
		$product_id = (int) $product_id;
		$record     = get_post_meta( $product_id, self::PUSHED_META, true );
		if ( ! is_array( $record ) || ! empty( $record['read'] ) || empty( $record['ids'] ) || ! is_array( $record['ids'] ) ) {
			return array();
		}

		// Only a read of the listing the push sent its photos to counts.
		if ( '' === (string) ( $record['item'] ?? '' ) || (string) $record['item'] !== (string) get_post_meta( $product_id, '_ebay_item_id', true ) ) {
			return array();
		}

		// The listing as the import already holds it: no read after the push.
		$places = self::listing_pictures( $entries );
		$stored = get_post_meta( $product_id, '_tcgiant_external_image_urls', true );
		if ( $places && is_array( $stored ) && self::numbers_in( $places ) === self::numbers_in( self::listing_pictures( $stored ) ) ) {
			return array();
		}

		// The first read after the push: whatever it finds, the record is
		// spent.
		$record['read']   = time();
		$record['learnt'] = 0;
		update_post_meta( $product_id, self::PUSHED_META, $record );

		$out = self::places_of( $product_id, $record, $places );
		if ( ! $out ) {
			return array();
		}

		foreach ( $out as $index => $id ) {
			self::remember( $id, $places[ $index ], true );
		}
		$record['learnt'] = count( $out );
		update_post_meta( $product_id, self::PUSHED_META, $record );

		return $out;
	}

	/**
	 * The photos a push record sent, by the places of the listing's pictures,
	 * when every check of learn_by_place() holds; none otherwise.
	 *
	 * @param int      $product_id Product.
	 * @param array    $record     Its push record.
	 * @param string[] $places     The listing's pictures (listing_pictures()).
	 * @return int[] Entry index => attachment ID.
	 */
	private static function places_of( $product_id, array $record, array $places ) {
		if ( self::id_list( (array) ( $record['shown'] ?? array() ) ) !== self::shown( $product_id ) ) {
			return array();
		}

		$ids  = array_values( array_map( 'intval', $record['ids'] ) );
		$urls = array_values( array_map( 'strval', (array) ( $record['urls'] ?? array() ) ) );
		if ( count( $places ) !== count( $ids ) || count( $urls ) !== count( $ids ) ) {
			return array();
		}

		$out = array();
		$n   = 0;
		foreach ( $places as $index => $url ) {
			$number = self::number( $url );
			$sent   = self::number( $urls[ $n ] );
			$id     = $ids[ $n ];
			$n++;

			if ( '' === $number || ( '' !== $sent && $sent !== $number ) ) {
				return array();
			}
			if ( ! get_post( $id ) || get_post_meta( $id, '_tcgiant_external_url', true ) ) {
				return array();
			}

			// A number already known is that photo's: one known on another
			// photo - of this product or any other, learnt by place or not -
			// says the places moved. One known on the photo in its place is
			// eBay's copy of an address it had copied before, or the upload
			// the push sent.
			$known = self::own_photo( $product_id, $url );
			if ( $known < 1 ) {
				$known = self::uploaded_as( self::PLACE . $number );
			}
			if ( $known > 0 && $known !== $id ) {
				return array();
			}
			$out[ $index ] = $id;
		}

		return $out;
	}

	/**
	 * Whether a push record sent its photos as eBay's own addresses: uploads
	 * (or stand-ins), whose numbers the push knew. Never a mix.
	 *
	 * @param array $record A push record.
	 * @return bool
	 */
	private static function sent_from_ebay( array $record ) {
		$urls = array_values( array_map( 'strval', (array) ( $record['urls'] ?? array() ) ) );

		return $urls && '' !== self::number( $urls[0] );
	}

	/**
	 * The pictures of a pushed product's listing that may be copies eBay made
	 * of a push from the website that taught nothing - one before 3.30.4,
	 * which recorded nothing, such as the pushes that fell back to the
	 * website's addresses when the call ceiling refused their uploads on
	 * 2026-11-03; or one recorded since whose first read could not be matched
	 * by place (learn_by_place()). The image batch holds those, if no picture
	 * among them is one it knows (TCGiant_Sync_Image_Localizer::
	 * localize_product_images()): nothing downloaded, nothing on the product
	 * changed, nothing learnt, the pictures kept on the product as held -
	 * never downloaded for it afterwards - and the product named for the shop
	 * (hold()). Downloading them made copies of the product's own photos that
	 * nothing could ever tell apart from new ones, and the next push sent the
	 * copies too. Their numbers are not learnt by place: nothing says what
	 * such a push sent, and the product's photos may have changed since.
	 *
	 * Only when nothing on eBay could be new to the product: it has been
	 * pushed, within HOLD_DAYS; it has a main photo of its own; its record for
	 * this listing, if any, taught nothing - not a push that sent eBay's own
	 * addresses, nor one whose first read learnt its places, nor a revise that
	 * kept eBay's photos; the listing has no variation pictures; and each of
	 * its pictures carries a number, with no more of them than the photos the
	 * product shows (up to the 24 a listing takes) - one fewer, under
	 * GetItem's other possible shape, or fewer still, where a push left some
	 * out. A listing with a picture added on eBay, past HOLD_DAYS since the
	 * last push, or of a product following eBay's photos, comes in as before.
	 * Pushed again under 3.30.4, the product is recorded, and the next read
	 * learns the numbers where it can.
	 *
	 * @param int   $product_id Product.
	 * @param array $entries    The import's entries.
	 * @return string[] The pictures to look for, or none when the listing is
	 *                  not one to hold.
	 */
	public static function may_hold( $product_id, array $entries ) {
		$product_id = (int) $product_id;
		if ( ! self::pushed( $product_id ) ) {
			return array();
		}

		$main = (int) get_post_thumbnail_id( $product_id );
		if ( $main < 1 || get_post_meta( $main, '_tcgiant_external_url', true ) ) {
			return array();
		}

		$record = get_post_meta( $product_id, self::PUSHED_META, true );
		$ours   = is_array( $record ) && (string) ( $record['item'] ?? '' ) === (string) get_post_meta( $product_id, '_ebay_item_id', true );
		if ( $ours && ( ! empty( $record['kept'] ) || ! empty( $record['learnt'] ) || self::sent_from_ebay( $record ) ) ) {
			return array();
		}

		// No more pictures than the push sent, and one added on eBay, for a
		// record of this listing that taught nothing; otherwise no more than
		// the photos the product shows.
		$most = $ours ? count( (array) ( $record['ids'] ?? array() ) ) + 1 : count( self::shown( $product_id ) );

		if ( self::pushed_at( $product_id ) < time() - self::HOLD_DAYS * 86400 ) {
			return array();
		}

		// Its held pictures asked for by the shop (release()).
		if ( (string) get_post_meta( $product_id, self::RELEASED_META, true ) === (string) get_post_meta( $product_id, '_ebay_item_id', true ) ) {
			return array();
		}

		$places = self::listing_pictures( $entries );
		$given  = array_filter( array_map( function ( $entry ) {
			return is_array( $entry ) ? (string) ( $entry[0] ?? '' ) : (string) $entry;
		}, $entries ), function ( $url ) {
			return '' !== $url;
		} );
		if ( ! $places || count( $places ) !== count( $given ) || count( $places ) > min( 24, $most ) ) {
			return array();
		}

		// The product's own eBay stand-ins' pictures are photos of its own
		// still to come (a download paused part way, one given up on), not
		// copies of any.
		$standins = array();
		foreach ( self::shown( $product_id ) as $id ) {
			$external = (string) get_post_meta( $id, '_tcgiant_external_url', true );
			if ( '' !== $external ) {
				$standins[] = self::number( $external );
				$standins[] = self::number( (string) get_post_meta( $id, '_tcgiant_source_url', true ) );
			}
		}

		foreach ( $places as $url ) {
			$number = self::number( $url );
			if ( '' === $number || in_array( $number, $standins, true ) ) {
				return array();
			}
		}

		return array_values( $places );
	}

	/**
	 * Hold a product's listing's pictures: kept on it as held, never
	 * downloaded for it, and the product named for Image Cleanup.
	 *
	 * @param int      $product_id Product.
	 * @param string[] $urls       The pictures held.
	 * @return void
	 */
	public static function hold( $product_id, array $urls ) {
		$numbers = array_values( array_unique( array_merge( self::held_numbers( $product_id ), array_filter( array_map( array( __CLASS__, 'number' ), $urls ), function ( $number ) {
			return '' !== $number;
		} ) ) ) );
		update_post_meta( (int) $product_id, self::HELD_META, array(
			'at'      => time(),
			'numbers' => $numbers,
		) );
	}

	/**
	 * The numbers of the pictures held for a product (hold()).
	 *
	 * @param int $product_id Product.
	 * @return string[]
	 */
	public static function held_numbers( $product_id ) {
		$held = get_post_meta( (int) $product_id, self::HELD_META, true );

		return is_array( $held ) ? array_values( array_map( 'strval', (array) ( $held['numbers'] ?? array() ) ) ) : array();
	}

	/**
	 * The products whose listing's pictures were held, for Image Cleanup:
	 * up to $limit of them, by ID, and how many there are.
	 *
	 * @param int $limit Most to give.
	 * @return array [ int[] IDs, int total ]
	 */
	public static function held_products( $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT post_id ) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::HELD_META ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $total > 0 ? $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d", self::HELD_META, (int) $limit ) ) : array();

		return array( array_map( 'intval', (array) $ids ), $total );
	}

	/**
	 * Let go of a product's held pictures, when the shop asks for its
	 * listing's photos with an images-only sync
	 * (TCGiant_Sync_Importer::start_images_only_sync()): they may be photos
	 * it changed on eBay itself. They are no longer held for this listing,
	 * and the read the sync makes queues them for the image batch.
	 *
	 * @param int $product_id Product.
	 * @return bool Whether it had any held.
	 */
	public static function release( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! get_post_meta( $product_id, self::HELD_META, true ) ) {
			return false;
		}

		delete_post_meta( $product_id, self::HELD_META );
		update_post_meta( $product_id, self::RELEASED_META, (string) get_post_meta( $product_id, '_ebay_item_id', true ) );
		delete_post_meta( $product_id, '_tcgiant_image_urls_hash' );

		return true;
	}

	/**
	 * The photo a downloaded copy is a copy of: its source's number is one
	 * another photo was put on eBay as. 0 for anything else - a photo of the
	 * shop's own, an eBay stand-in, a download of a picture the site never
	 * put on eBay.
	 *
	 * @param int $attachment_id Photo.
	 * @return int The photo it copies, or 0.
	 */
	public static function original_of( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 || get_post_meta( $attachment_id, '_tcgiant_external_url', true ) ) {
			return 0;
		}

		$number = self::number( (string) get_post_meta( $attachment_id, '_tcgiant_source_url', true ) );

		return '' === $number ? 0 : self::uploaded_as( $number, $attachment_id );
	}

	/**
	 * The photo itself, from a copy of it or a copy of a copy - or the photo
	 * given, when it is no copy (or the copies go round in a circle).
	 *
	 * Each step only to a photo that can stand in its copy's place: one with
	 * a file on record, and one whose file is on this server unless the copy's
	 * is not either - a site whose files an offloader keeps elsewhere. With a
	 * product, only to a photo of that product's own: attached to it, shown by
	 * it, or sent by its last recorded push - never another product's upload,
	 * as a listing made on eBay with "Sell similar" shows.
	 *
	 * @param int $attachment_id Photo.
	 * @param int $product_id    The product it must be a photo of, or 0.
	 * @return int
	 */
	public static function root_of( $attachment_id, $product_id = 0 ) {
		$start = (int) $attachment_id;
		$id    = $start;
		$seen  = array();

		for ( $i = 0; $i < self::CHAIN_MAX; $i++ ) {
			$seen[ $id ] = true;
			$original    = self::original_of( $id );
			if ( $original < 1 || ! self::stands_in_for( $original, $id ) || ( $product_id > 0 && ! self::belongs( $product_id, $original ) ) ) {
				return $id;
			}
			if ( isset( $seen[ $original ] ) ) {
				return $start;
			}
			$id = $original;
		}

		return $start;
	}

	/**
	 * Whether a photo can be shown in place of a copy of it: a file on record,
	 * on this server unless the copy's file is not here either.
	 *
	 * @param int $original The photo.
	 * @param int $copy     Its copy.
	 * @return bool
	 */
	public static function stands_in_for( $original, $copy ) {
		if ( '' === (string) get_post_meta( (int) $original, '_wp_attached_file', true ) ) {
			return false;
		}

		return self::file_here( (int) $original ) || ! self::file_here( (int) $copy );
	}

	/**
	 * Whether a photo's file is on this server, where its record says.
	 *
	 * @param int $attachment_id Photo.
	 * @return bool
	 */
	private static function file_here( $attachment_id ) {
		$relative = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
		if ( '' === $relative ) {
			return false;
		}

		if ( 0 === strpos( $relative, '/' ) || preg_match( '#^[A-Za-z]:[/\\\\]#', $relative ) ) {
			return is_file( $relative );
		}

		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$uploads = wp_get_upload_dir();
		} elseif ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
		} else {
			return false;
		}

		return is_file( rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' ) . '/' . $relative );
	}

	/**
	 * The post a photo is attached to.
	 *
	 * @param int $attachment_id Photo.
	 * @return int
	 */
	private static function parent_of( $attachment_id ) {
		$post = get_post( (int) $attachment_id );

		return is_object( $post ) ? (int) $post->post_parent : 0;
	}

	/**
	 * Whether a photo is one of a product's own: attached to it, shown by it,
	 * or sent by its last recorded push.
	 *
	 * @param int $product_id Product.
	 * @param int $photo      Photo.
	 * @return bool
	 */
	public static function belongs( $product_id, $photo ) {
		$photo = (int) $photo;
		if ( self::parent_of( $photo ) === (int) $product_id || in_array( $photo, self::shown( $product_id ), true ) ) {
			return true;
		}

		return self::recorded( $product_id, $photo );
	}

	/**
	 * Keep on a photo the numbers of a copy of it, and of every copy between,
	 * so that a listing still showing one of them is matched to the photo
	 * once the copy is no longer shown, or no longer there.
	 *
	 * @param int $copy The copy.
	 * @param int $root The photo it copies (root_of()).
	 * @return void
	 */
	public static function carry_numbers( $copy, $root ) {
		$id = (int) $copy;
		for ( $i = 0; $i < self::CHAIN_MAX && $id > 0 && $id !== (int) $root; $i++ ) {
			// Each as it is kept: one learnt by place stays so.
			foreach ( (array) get_post_meta( $id, self::NUMBER_META, false ) as $value ) {
				$value = (string) $value;
				$place = 0 === strpos( $value, self::PLACE );
				self::remember( (int) $root, $place ? substr( $value, strlen( self::PLACE ) ) : $value, $place );
			}
			$cached = self::number( (string) get_post_meta( $id, '_tcgiant_eps_url', true ) );
			if ( '' !== $cached ) {
				self::remember( (int) $root, $cached );
			}
			$id = self::original_of( $id );
		}
	}

	/**
	 * Which of these photos are downloaded copies of a photo this site put on
	 * eBay, with the photo each copies: for Image Cleanup, read a page at a
	 * time (TCGiant_Sync_Photo_Cleanup::verdicts()). The photo copied must be
	 * there - in the Media Library, not its trash, with a file on record that
	 * can stand in for the copy's (stands_in_for()) - no stand-in, and not
	 * waiting to be removed or given up on; and it must be a photo of the
	 * copy's own product, which the shop has pushed (belongs()).
	 *
	 * @param int[] $ids Photos.
	 * @return int[]|null Copy => the photo it copies; null when the database could not answer.
	 */
	public static function copies_among( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$in} ) AND meta_key = '_tcgiant_source_url'" );
			if ( self::read_failed() ) {
				return null;
			}

			$by_number = array();
			foreach ( (array) $rows as $row ) {
				$number = self::number( (string) $row->meta_value );
				if ( '' !== $number ) {
					$by_number[ $number ][] = (int) $row->post_id;
				}
			}
			if ( ! $by_number ) {
				continue;
			}

			$marks = implode( ', ', array_fill( 0, count( $by_number ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$originals = $wpdb->get_results( $wpdb->prepare(
				"SELECT n.post_id, n.meta_value FROM {$wpdb->postmeta} n
				 INNER JOIN {$wpdb->posts} a ON a.ID = n.post_id AND a.post_type = 'attachment' AND a.post_status = 'inherit'
				 LEFT JOIN {$wpdb->postmeta} ext ON ext.post_id = n.post_id AND ext.meta_key = '_tcgiant_external_url'
				 LEFT JOIN {$wpdb->postmeta} gone ON gone.post_id = n.post_id AND gone.meta_key IN ( '_tcgiant_delete_failed', '_tcgiant_delete_after' )
				 WHERE n.meta_key = %s
				   AND n.meta_value IN ( {$marks} )
				   AND ext.post_id IS NULL
				   AND gone.post_id IS NULL",
				array_merge( array( self::NUMBER_META ), array_map( 'strval', array_keys( $by_number ) ) )
			) );
			if ( self::read_failed() ) {
				return null;
			}

			foreach ( (array) $originals as $row ) {
				$original = (int) $row->post_id;
				foreach ( $by_number[ (string) $row->meta_value ] ?? array() as $copy ) {
					if ( $copy === $original || isset( $out[ $copy ] ) ) {
						continue;
					}
					$parent = self::parent_of( $copy );
					if ( $parent < 1 || ! self::pushed( $parent ) || ! self::belongs( $parent, $original ) || ! self::stands_in_for( $original, $copy ) ) {
						continue;
					}
					$out[ $copy ] = $original;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether the database failed the read just made: wpdb answers one with
	 * an empty result, which must not be taken for "none". Asked after each
	 * read, so its answer changes from one call to the next.
	 *
	 * @phpstan-impure
	 *
	 * @return bool
	 */
	private static function read_failed() {
		global $wpdb;

		return '' !== (string) $wpdb->last_error;
	}

	// -------------------------------------------------------------------------
	// The pass, once, over products damaged before 3.30.4
	// -------------------------------------------------------------------------

	/**
	 * Queue the pass until it has finished - see run_heal(). Asked on admin
	 * requests, at most every ten minutes, so a pass whose run was killed -
	 * which queues nothing after it - is queued again.
	 *
	 * @return void
	 */
	public static function maybe_queue_heal() {
		$record = get_option( self::HEAL_OPTION, array() );
		if ( is_array( $record ) && ! empty( $record['done'] ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		if ( get_transient( self::HEAL_TICK ) ) {
			return;
		}
		set_transient( self::HEAL_TICK, 1, 10 * MINUTE_IN_SECONDS );

		if ( false !== as_next_scheduled_action( self::HEAL_HOOK, null, self::HEAL_GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HEAL_HOOK, array(), self::HEAL_GROUP );
	}

	/**
	 * Show each pushed product its own photos again in place of the copies the
	 * old sync downloaded of them, once.
	 *
	 * First, a batch at a time in the order they were written, every copy an
	 * upload before 3.30.4 left cached (_tcgiant_eps_url) has its number kept
	 * on its photo, so that a copy of it is known for one. Then every product
	 * the plugin imported photos for (_tcgiant_external_image_urls), in ID
	 * order, a batch at a time, where it has got to kept after each product
	 * (heal_product()). Only a product the shop has pushed is changed, and
	 * only where it shows a copy: a copy whose photo it shows already is
	 * dropped, any other is shown as the photo itself, in the same place
	 * (root_of(), the photo the product's own), and every other entry stays
	 * as the shop saved it. The copy's numbers are kept on the photo, so a
	 * listing still showing them is matched to it. A product the image batch
	 * is working on is left until it has finished. Its main photo is changed
	 * only that way: a copy of a photo is that photo, which is certain; what
	 * the main photo was before a sync made a gallery photo's copy the main
	 * photo is not, and those products are named for the shop to look at.
	 *
	 * Only a product's main photo and gallery lists are written, each over the
	 * row read and only while it still holds what was read, and number rows
	 * added. No photo is deleted or changed - the copies, now unused, are
	 * Image Cleanup's - and nothing is asked of eBay. A run killed part way
	 * carries on after the last product it finished when queued again by
	 * maybe_queue_heal(), and the log says so. Once through, it is marked
	 * done and says what it did in one line, then names the products whose
	 * live eBay listing shows another photo first, those that no longer show
	 * a photo of their own still attached to them, and those whose gallery
	 * ends with what may be copies of a push from the website.
	 *
	 * @return void
	 */
	public static function run_heal() {
		global $wpdb;

		$record = get_option( self::HEAL_OPTION, array() );
		$record = array_merge(
			array(
				'phase'    => 'numbers',
				'cursor'   => 0,
				'numbers'  => 0,
				'checked'  => 0,
				'healed'   => 0,
				'mains'    => 0,
				'started'  => time(),
				'done'     => 0,
				'stopped'  => 0,
				'wrong'    => array(),
				'review'   => array(),
				'tails'    => array(),
				'deferred' => array(),
				'rounds'   => 0,
				'died_on'  => 0,
				'skipped'  => array(),
			),
			is_array( $record ) ? $record : array()
		);

		if ( ! empty( $record['done'] ) ) {
			return;
		}

		// A run is under way from here to its record, naming the product it
		// is working on: one found under way was stopped before it got there.
		// Stopped twice on the same product, the pass goes on past it, and
		// names it: a product it cannot finish within the host's limits must
		// not hold up every product after it for ever.
		$open = get_option( self::HEAL_OPEN_OPTION, false );
		if ( false !== $open ) {
			$record['stopped']++;
			$open     = (int) $open;
			$deferred = array_map( 'intval', (array) $record['deferred'] );
			$on_it    = 'products' === $record['phase'] ? $open > (int) $record['cursor'] : 'deferred' === $record['phase'] && in_array( $open, $deferred, true );
			if ( $open > 0 && $on_it ) {
				if ( $open === (int) ( $record['died_on'] ?? 0 ) ) {
					if ( 'products' === $record['phase'] ) {
						$record['cursor'] = $open;
					} else {
						$record['deferred'] = array_values( array_diff( $deferred, array( $open ) ) );
					}
					if ( count( (array) ( $record['skipped'] ?? array() ) ) < self::LIST_KEEP ) {
						$record['skipped'][] = $open;
					}
				} else {
					$record['died_on'] = $open;
				}
			}
			// Kept now: this run may be stopped on the same product.
			update_option( self::HEAL_OPTION, $record, false );
		}
		update_option( self::HEAL_OPEN_OPTION, 0, false );

		if ( 'numbers' === $record['phase'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_tcgiant_eps_url'
				   AND meta_id > %d
				 ORDER BY meta_id ASC
				 LIMIT %d",
				(int) $record['cursor'],
				self::NUMBERS_BATCH
			) );

			if ( '' !== (string) $wpdb->last_error ) {
				delete_option( self::HEAL_OPEN_OPTION );
				return;
			}

			if ( empty( $rows ) ) {
				$record['phase']  = 'products';
				$record['cursor'] = 0;
			} else {
				foreach ( $rows as $row ) {
					if ( self::remember( (int) $row->post_id, (string) $row->meta_value ) ) {
						$record['numbers']++;
					}
					$record['cursor'] = (int) $row->meta_id;
				}
			}

			update_option( self::HEAL_OPTION, $record, false );
			delete_option( self::HEAL_OPEN_OPTION );
			as_enqueue_async_action( self::HEAL_HOOK, array(), self::HEAL_GROUP );
			return;
		}

		if ( 'deferred' === $record['phase'] ) {
			$waiting = array();
			foreach ( array_map( 'intval', (array) $record['deferred'] ) as $product_id ) {
				if ( self::being_localized( $product_id ) && $record['rounds'] < self::DEFER_ROUNDS ) {
					$waiting[] = $product_id;
					continue;
				}
				update_option( self::HEAL_OPEN_OPTION, $product_id, false );
				self::heal_product( $product_id, $record );
			}
			$record['deferred'] = $waiting;
			$record['rounds']++;

			if ( $waiting ) {
				update_option( self::HEAL_OPTION, $record, false );
				delete_option( self::HEAL_OPEN_OPTION );
				self::queue_later();
				return;
			}

			self::finish( $record );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_tcgiant_external_image_urls'
			   AND post_id > %d
			 ORDER BY post_id ASC
			 LIMIT %d",
			(int) $record['cursor'],
			self::HEAL_BATCH
		) );

		// A read the database failed is not the end of the list.
		if ( '' !== (string) $wpdb->last_error ) {
			delete_option( self::HEAL_OPEN_OPTION );
			return;
		}

		if ( empty( $ids ) ) {
			if ( $record['deferred'] ) {
				$record['phase'] = 'deferred';
				update_option( self::HEAL_OPTION, $record, false );
				delete_option( self::HEAL_OPEN_OPTION );
				as_enqueue_async_action( self::HEAL_HOOK, array(), self::HEAL_GROUP );
				return;
			}

			self::finish( $record );
			return;
		}

		foreach ( $ids as $product_id ) {
			$product_id = (int) $product_id;

			update_option( self::HEAL_OPEN_OPTION, $product_id, false );

			$record['checked']++;
			$record['cursor'] = $product_id;

			// The image batch is working on it: its gallery would be written
			// back over this one. Looked at again once that has finished.
			if ( self::being_localized( $product_id ) ) {
				$record['deferred'][] = $product_id;
			} else {
				self::heal_product( $product_id, $record );
			}

			// Where it has got to, after each product: a batch the host
			// stops part way carries on from the product it was working on,
			// and goes past that one once stopped on it twice (above).
			update_option( self::HEAL_OPTION, $record, false );
		}

		delete_option( self::HEAL_OPEN_OPTION );

		as_enqueue_async_action( self::HEAL_HOOK, array(), self::HEAL_GROUP );
	}

	/**
	 * Whether the image batch is working on a product now: a lease still
	 * fresh (TCGiant_Sync_Image_Localizer::LEASE_SECONDS).
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	private static function being_localized( $product_id ) {
		if ( '' === (string) get_post_meta( (int) $product_id, '_tcgiant_localize_inflight', true ) ) {
			return false;
		}

		return (int) get_post_meta( (int) $product_id, '_tcgiant_localize_retry_after', true ) > time();
	}

	/**
	 * Run the pass again in a few minutes.
	 *
	 * @return void
	 */
	private static function queue_later() {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::DEFER_SECONDS, self::HEAL_HOOK, array(), self::HEAL_GROUP );
			return;
		}
		as_enqueue_async_action( self::HEAL_HOOK, array(), self::HEAL_GROUP );
	}

	/**
	 * Mark the pass done and say what it did.
	 *
	 * @param array $record The pass's record.
	 * @return void
	 */
	private static function finish( array $record ) {
		$record['done'] = time();
		update_option( self::HEAL_OPTION, $record, false );
		delete_option( self::HEAL_OPEN_OPTION );
		self::say_healed( $record );
	}

	/**
	 * One product: each photo it shows that is a copy shown as the photo
	 * itself, in its place, or dropped where the product shows that photo
	 * already. See run_heal().
	 *
	 * @param int   $product_id Product.
	 * @param array $record     The pass's record, counted into.
	 * @return void
	 */
	private static function heal_product( $product_id, array &$record ) {
		global $wpdb;

		// A product never pushed cannot show a copy of a photo of its own that
		// this site put on eBay: a picture another product uploaded, shown by a
		// listing made from that one on eBay, is its own download.
		if ( ! self::pushed( $product_id ) ) {
			return;
		}

		$main_row    = self::meta_row( $product_id, '_thumbnail_id' );
		$gallery_row = self::meta_row( $product_id, '_product_image_gallery' );

		$main    = $main_row ? (int) $main_row->meta_value : 0;
		$was     = $gallery_row ? (string) $gallery_row->meta_value : '';
		$gallery = array_values( array_filter( array_map( 'intval', explode( ',', $was ) ) ) );

		$roots = array();
		foreach ( array_merge( array( $main ), $gallery ) as $id ) {
			if ( $id > 0 && ! isset( $roots[ $id ] ) ) {
				$roots[ $id ] = self::root_of( $id, $product_id );
			}
		}

		$new_main = $main > 0 ? $roots[ $main ] : 0;

		// The photos the product shows that are no copy, and those this pass
		// puts in: a copy of one of them is dropped, not shown twice.
		$present = array();
		foreach ( $roots as $id => $root ) {
			if ( $root === $id ) {
				$present[ $id ] = true;
			}
		}
		if ( $new_main > 0 ) {
			$present[ $new_main ] = true;
		}

		$kept       = array();
		$replaced   = array();
		$introduced = array();
		foreach ( $gallery as $id ) {
			$root = $roots[ $id ];

			// No copy: as the shop saved it, repeats and all - unless it is
			// a photo this pass has just put in, in a copy's place.
			if ( $root === $id ) {
				if ( ! isset( $introduced[ $id ] ) ) {
					$kept[] = $id;
				}
				continue;
			}
			$replaced[ $id ] = $root;

			// The main photo in the gallery too, as the shop had it: the
			// photo itself there, once.
			if ( $id === $main ) {
				if ( ! in_array( $root, $kept, true ) ) {
					$kept[]              = $root;
					$introduced[ $root ] = true;
				}
				continue;
			}
			if ( isset( $present[ $root ] ) ) {
				continue;
			}
			$present[ $root ]    = true;
			$introduced[ $root ] = true;
			$kept[]              = $root;
		}
		if ( $new_main !== $main ) {
			$replaced[ $main ] = $new_main;
		}

		if ( ! $replaced ) {
			self::note_listing( $product_id, $record );
			return;
		}

		// The copies' numbers onto the photos they copy first: a listing still
		// showing them then finds the photo, whatever becomes of the copies.
		foreach ( $replaced as $copy => $root ) {
			self::carry_numbers( $copy, $root );
		}

		$main_changed    = $main_row && $new_main !== $main;
		$gallery_changed = $gallery_row && $kept !== $gallery;
		$wrote           = false;

		// Each written over the row read, only while it still holds what was
		// read: one the image batch or an import changes meanwhile is left
		// to it, as it applies the same rules now.
		//
		// The gallery first: a run stopped between the two leaves a main
		// photo copy the next run puts right as this one would. The other
		// way round, it would take the main photo's own entry in the gallery
		// for a copy of a photo already shown.
		if ( $gallery_changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $wpdb->update( $wpdb->postmeta, array( 'meta_value' => implode( ',', $kept ) ), array( 'meta_id' => (int) $gallery_row->meta_id, 'meta_value' => $was ) ) ) {
				$wrote = true;
			}
		}
		if ( $main_changed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( $wpdb->update( $wpdb->postmeta, array( 'meta_value' => (string) $new_main ), array( 'meta_id' => (int) $main_row->meta_id, 'meta_value' => (string) $main_row->meta_value ) ) ) {
				$wrote = true;
				$record['mains']++;
			}
		}

		// A push recorded before the pass got here: what it sent are these
		// photos now, copies and all. Unread, its first read still learns
		// eBay's copies by their places, as the photos they show; read, the
		// place numbers its read left on the copies - carried onto the photos
		// above - still count for this product (recorded()).
		if ( $wrote ) {
			$pushed = get_post_meta( $product_id, self::PUSHED_META, true );
			if ( is_array( $pushed ) && ! empty( $pushed['ids'] ) ) {
				$to = function ( $ids ) use ( $replaced ) {
					return array_map( function ( $id ) use ( $replaced ) {
						$id = (int) $id;
						return isset( $replaced[ $id ] ) && $replaced[ $id ] > 0 ? (int) $replaced[ $id ] : $id;
					}, (array) $ids );
				};
				$pushed['ids']   = $to( $pushed['ids'] );
				$pushed['shown'] = self::id_list( $to( $pushed['shown'] ?? array() ) );
				update_post_meta( $product_id, self::PUSHED_META, $pushed );
			}
		}

		if ( $wrote ) {
			// The product's caches: nothing is pushed by it.
			if ( function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $product_id );
			} else {
				wp_cache_delete( $product_id, 'post_meta' );
			}
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			$record['healed']++;
		}

		self::note_listing( $product_id, $record );

		// A photo of its own, attached to it, that it no longer shows - not a
		// copy, nor an eBay stand-in: once its copies are its own photos
		// again, one whose copy it never showed is the one a listing went
		// without - when a new listing went up without its main photo and a
		// sync made a gallery photo's copy the main photo, the main photo
		// before it. Only on a product put right here.
		if ( ! $wrote ) {
			return;
		}
		$shown = array_flip( self::shown( $product_id ) );
		foreach ( (array) get_posts( array(
			'post_type'   => 'attachment',
			'post_parent' => $product_id,
			'fields'      => 'ids',
			'numberposts' => -1,
		) ) as $id ) {
			$id = (int) $id;
			if ( isset( $shown[ $id ] ) || get_post_meta( $id, '_tcgiant_external_url', true ) || '' === (string) get_post_meta( $id, '_wp_attached_file', true ) || self::original_of( $id ) > 0 ) {
				continue;
			}
			if ( count( $record['review'] ) < self::LIST_KEEP ) {
				$record['review'][ $product_id ] = $id;
			}
			break;
		}
	}

	/**
	 * What the pass notes of a pushed product's listing, put right or not.
	 * Neither changes anything: they are named for the shop.
	 *
	 * - tails: it may show copies of its own photos that a sync downloaded
	 *   after a push from the website it has no record of - downloads nothing
	 *   recognises (unrecognised()), made within COPIES_DAYS after the
	 *   product's last push, of the listing's own pictures, in order:
	 *   at the end of the gallery, behind as many photos as the listing has
	 *   pictures, or one more (GetItem's other possible shape); or in place
	 *   of its photos ("Overwrite Images" on), photos of its own attached to
	 *   it and not shown. A push would send those copies to eBay.
	 * - wrong: otherwise, its live listing, as last read, shows another of
	 *   its photos first - one it is on eBay as, or an eBay stand-in, never
	 *   a download nothing recognises.
	 *
	 * @param int   $product_id Product.
	 * @param array $record     The pass's record, noted into.
	 * @return void
	 */
	private static function note_listing( $product_id, array &$record ) {
		$main_now = (int) get_post_thumbnail_id( $product_id );
		$live     = get_post_meta( $product_id, '_ebay_live', true );
		$item     = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		if ( $main_now < 1 || ! is_array( $live ) || '' === $item || $item !== (string) ( $live['item_id'] ?? '' ) || empty( $live['pictures'] ) ) {
			return;
		}
		$pictures = array_values( array_map( function ( $picture ) {
			return is_string( $picture ) ? $picture : '';
		}, (array) $live['pictures'] ) );

		$copies = self::copies_shown( $product_id, $pictures );
		if ( $copies > 0 ) {
			if ( count( $record['tails'] ) < self::LIST_KEEP ) {
				$record['tails'][ $product_id ] = $copies;
			}
			return;
		}

		if ( 'Active' === (string) get_post_meta( $product_id, '_ebay_listing_status', true ) ) {
			$first = self::photo_numbered( $product_id, self::number( $pictures[0] ) );
			if ( $first > 0 && $first !== self::root_of( $main_now, $product_id ) && count( $record['wrong'] ) < self::LIST_KEEP && ! in_array( $product_id, $record['wrong'], true ) ) {
				$record['wrong'][] = $product_id;
			}
		}
	}

	/**
	 * How many of the photos a product shows may be copies of its own that a
	 * sync downloaded after a push from the website it has no record of
	 * (note_listing()): 0 when its photos do not have that shape. Public
	 * since 3.32.1 fix round 1: a relist that would send such a product's
	 * photos is refused instead, as it would put the copies on eBay
	 * (TCGiant_Sync_Exporter::relist_photos()).
	 *
	 * @param int        $product_id Product.
	 * @param string[]   $pictures   Its listing's pictures, as last read.
	 * @param int[]|null $shown      The photos it shows, main photo first, as
	 *                               shown() gives them; null to read them.
	 * @return int
	 */
	public static function copies_shown( $product_id, array $pictures, $shown = null ) {
		$k     = count( $pictures );
		$shown = null === $shown ? self::shown( $product_id ) : self::id_list( (array) $shown );
		$after = self::pushed_at( $product_id );
		if ( $k < 1 || $after < 1 ) {
			return 0;
		}

		// Behind the photos ("Overwrite Images" off): as many as the
		// listing's pictures, or one more.
		foreach ( array( $k, $k + 1 ) as $head ) {
			if ( count( $shown ) === $head + $k && self::downloads_of( array_slice( $shown, $head ), $pictures, $after ) ) {
				return $k;
			}
		}

		// In their place ("Overwrite Images" on): photos of its own, as many
		// at least, attached to it and not shown.
		if ( count( $shown ) === $k && self::downloads_of( $shown, $pictures, $after ) ) {
			$own = 0;
			foreach ( (array) get_posts( array(
				'post_type'   => 'attachment',
				'post_parent' => (int) $product_id,
				'fields'      => 'ids',
				'numberposts' => -1,
			) ) as $id ) {
				$id = (int) $id;
				if ( ! in_array( $id, $shown, true ) && ! get_post_meta( $id, '_tcgiant_external_url', true ) && '' !== (string) get_post_meta( $id, '_wp_attached_file', true ) && self::original_of( $id ) < 1 ) {
					$own++;
				}
			}
			if ( $own >= $k ) {
				return $k;
			}
		}

		return 0;
	}

	/**
	 * Whether these photos are downloads nothing recognises of these
	 * pictures, one each, in order, each made within COPIES_DAYS after the
	 * product's last push - when the sync after a push from the website
	 * downloaded eBay's copies. One downloaded before the push (an import's)
	 * or long after it (a photo really changed on eBay) is the listing's own.
	 *
	 * @param int[]    $ids      Photos.
	 * @param string[] $pictures Pictures.
	 * @param int      $after    The product's last push (pushed_at()).
	 * @return bool
	 */
	private static function downloads_of( array $ids, array $pictures, $after ) {
		foreach ( array_values( $ids ) as $n => $id ) {
			$number = self::number( (string) get_post_meta( $id, '_tcgiant_source_url', true ) );
			if ( '' === $number || $number !== self::number( (string) ( $pictures[ $n ] ?? '' ) ) || ! self::unrecognised( $id ) ) {
				return false;
			}
			$at = self::made_at( $id );
			if ( $at < $after || $at > $after + self::COPIES_DAYS * 86400 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * When a photo was made, as a timestamp: its post_date_gmt, else its
	 * post_date in the site's time.
	 *
	 * @param int $id Photo.
	 * @return int
	 */
	private static function made_at( $id ) {
		$gmt = (string) get_post_field( 'post_date_gmt', (int) $id );
		if ( '' === $gmt || 0 === strpos( $gmt, '0000' ) ) {
			$local = (string) get_post_field( 'post_date', (int) $id );
			$gmt   = '' !== $local && function_exists( 'get_gmt_from_date' ) ? (string) get_gmt_from_date( $local ) : $local;
		}

		return '' === $gmt ? 0 : (int) strtotime( $gmt . ' UTC' );
	}

	/**
	 * Whether a photo is a download nothing recognises: downloaded from eBay,
	 * no copy of a photo this site put on eBay, and on eBay as no number of
	 * its own - what a sync made of eBay's copy of a photo pushed from the
	 * website before 3.30.4, as much as an imported photo.
	 *
	 * @param int $id Photo.
	 * @return bool
	 */
	private static function unrecognised( $id ) {
		return '' !== (string) get_post_meta( (int) $id, '_tcgiant_source_url', true )
			&& ! get_post_meta( (int) $id, '_tcgiant_external_url', true )
			&& self::original_of( (int) $id ) < 1
			&& ! self::numbers_of( (int) $id );
	}

	/**
	 * The photo of a product's own an eBay picture number is, through any
	 * copy of it: one it shows that is on eBay as the number or stands in for
	 * it, or that was downloaded from it and is recognised - never a download
	 * nothing recognises, which may be a copy of any of its photos - else any
	 * photo put on eBay as it.
	 *
	 * @param int    $product_id Product.
	 * @param string $number     eBay's number.
	 * @return int Attachment ID, or 0 when the number is no photo here.
	 */
	private static function photo_numbered( $product_id, $number ) {
		if ( '' === (string) $number ) {
			return 0;
		}

		foreach ( self::shown( $product_id ) as $id ) {
			$numbers   = self::numbers_of( $id, $product_id );
			$numbers[] = self::number( (string) get_post_meta( $id, '_tcgiant_external_url', true ) );
			if ( ! self::unrecognised( $id ) ) {
				$numbers[] = self::number( (string) get_post_meta( $id, '_tcgiant_source_url', true ) );
			}
			if ( in_array( (string) $number, $numbers, true ) ) {
				return self::root_of( $id, $product_id );
			}
		}

		$uploaded = self::uploaded_as( (string) $number );

		return $uploaded > 0 ? self::root_of( $uploaded, $product_id ) : 0;
	}

	/**
	 * A product's first row of a meta key, as the table holds it now.
	 *
	 * @param int    $product_id Product.
	 * @param string $key        Meta key.
	 * @return object|null meta_id and meta_value.
	 */
	private static function meta_row( $product_id, $key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
			(int) $product_id,
			$key
		) );

		return is_object( $row ) ? $row : null;
	}

	/**
	 * The lines a finished pass writes.
	 *
	 * @param array $record The pass's record.
	 * @return void
	 */
	private static function say_healed( array $record ) {
		TCGiant_Sync_Logger::log( sprintf(
			'Own photos: %1$d of %2$d product(s) showed a copy that came back from eBay of a photo of their own, %3$d of them as their main photo; each shows its own photo again, in its place. No photo was deleted: the copies, now unused, can be removed with Image Cleanup\'s check.%4$s',
			(int) $record['healed'],
			(int) $record['checked'],
			(int) $record['mains'],
			$record['stopped'] > 0
				? sprintf( ' A run was stopped part way %d time(s); it carried on after the last product it had finished.', (int) $record['stopped'] )
				: ''
		) );

		foreach ( array_chunk( array_map( 'intval', (array) $record['wrong'] ), 100 ) as $ids ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Own photos: the eBay listing shows another photo first for %1$d product(s), whose main photo here is their own: %2$s. Nothing changes that by itself: push them again, on a day their photos can be put on eBay, to put their main photo first.',
				count( $ids ),
				'WC #' . implode( ', WC #', $ids )
			) );
		}

		foreach ( array_chunk( (array) $record['review'], 100, true ) as $chunk ) {
			$names = array();
			foreach ( $chunk as $product_id => $photo_id ) {
				$names[] = sprintf( 'WC #%d (photo #%d)', (int) $product_id, (int) $photo_id );
			}
			TCGiant_Sync_Logger::warning( sprintf(
				'Own photos: %1$d product(s) no longer show a photo of their own still attached to them, which may have been their main photo before a new listing went up without it: %2$s. Check them, and choose their main photo again if it is not the right one.',
				count( $names ),
				implode( ', ', $names )
			) );
		}

		foreach ( array_chunk( array_map( 'intval', (array) $record['skipped'] ), 100 ) as $ids ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Own photos: %1$d product(s) were passed over, as the host stopped the pass twice while it was working on each: %2$s. Check that they show their own photos, not copies of them.',
				count( $ids ),
				'WC #' . implode( ', WC #', $ids )
			) );
		}

		foreach ( array_chunk( array_keys( (array) $record['tails'] ), 100 ) as $ids ) {
			TCGiant_Sync_Logger::warning( sprintf(
				'Own photos: %1$d product(s) may show copies of their own photos that a sync downloaded from eBay after a push from the website the plugin has no record of - at the end of the gallery, or in place of their photos: %2$s. Open each and compare: remove only a photo that looks the same as one of the product\'s own photos; keep any that is different - that is the listing\'s own, changed on eBay. Where copies took the place of its photos, choose its own photos again. Do it before you push them: a push would send the copies to eBay. Image Cleanup does not count these copies; delete them from the Media Library yourself.',
				count( $ids ),
				'WC #' . implode( ', WC #', array_map( 'intval', $ids ) )
			) );
		}
	}
}
