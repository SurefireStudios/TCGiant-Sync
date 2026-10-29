<?php
/**
 * Harness: a listing's photos are never a mix of eBay-hosted and website copies.
 *
 * With "Host listing images on eBay?" on, each photo was uploaded on its own
 * (UploadSiteHostedPictures). One that could not be - the day's call budget
 * spent, eBay refusing the file, a timeout, or a file not on this server
 * because a CDN offloader took it or it was deleted - went as its address on
 * the website, beside the copies of the others that came from the 20-day EPS
 * cache. eBay refuses that whole listing: "A mixture of Self Hosted and EPS
 * pictures are not allowed". On 2026-10-27 Numismax relisted 17 ended GTC
 * listings as 10-day auctions and every one was refused so. The missing-file
 * case said nothing in the log at all.
 *
 * An imported photo kept on eBay by "Keep eBay-hosted URLs" is already on
 * eBay - its own address is eBay's - and with any setting a listing holding
 * one sent it beside the shop's own photos' addresses: the same refusal.
 *
 * Now a listing's photos are never a mix, whatever the settings. With none
 * on eBay and the setting off: the website's addresses, as 3.27.7. With none
 * on eBay and the setting on: at the first photo that cannot be put on eBay,
 * nothing more is uploaded and every photo goes from the website, with one
 * warning a push. With one already on eBay: all on eBay, the shop's own
 * uploaded even with the setting off, and one that cannot be put on eBay
 * left out, with one warning a push naming each. Copies uploaded stay cached.
 *
 * Left out of a revise, though, a photo comes off the live listing: eBay's
 * Revise calls replace the photos with those they are sent. 3.27.7 failed
 * that revise whole with eBay's mixture error, so it never took a photo off.
 * Now a revise that would leave one out is sent no PictureDetails at all, and
 * the listing keeps its photos while everything else updates, with one
 * warning a push. A new listing - AddItem, AddFixedPriceItem, the one made
 * for an ended listing, and Verify, which checks an add - goes without the
 * photo, as before.
 *
 * Loaded for real: the exporter's item build and push (do_push(), the relist
 * of an ended listing included), the API class with its Trading calls played
 * and its picture upload played, the templates. The photos' files are real
 * files in this run's TMP.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// What a push needs of the plugin's other classes, played.
class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	// Settled once eBay's sales of the listing the product follows were taken
	// in, as the real class records it (_ebay_stock_settled_item): 3.28.0's
	// push of an ended listing asks before following a relist and again
	// before the new listing.
	public static function listing_already_settled( $product_id, $item_id ) { return '' !== (string) $item_id && (string) $item_id === (string) ( $GLOBALS['SETTLED_ITEM'][ (int) $product_id ] ?? '' ); }
	/**
	 * eBay's sales brought into the stock: what is left, as the product already
	 * holds it - unless $GLOBALS['SOLD_DOWN'] names the product. Then the stock
	 * comes down by what sold, stored as WooCommerce stores it, so the product
	 * object a push already holds keeps the old figure and only a fresh read
	 * has the new one.
	 */
	public static function apply_ended_listing_stock( $product_id, $listed, $sold ) {
		$GLOBALS['SETTLED_ITEM'][ (int) $product_id ] = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		$product = wc_get_product( $product_id );
		if ( empty( $GLOBALS['SOLD_DOWN'][ $product_id ] ) ) {
			return (int) $product->get_stock_quantity();
		}
		$left = max( 0, (int) $product->get_stock_quantity() - (int) $sold );
		new WC_Product( $product_id, array_merge( $product->data, array( 'stock' => $left ) ) );
		return $left;
	}
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}

function get_attached_file( $id ) { return $GLOBALS['W']['files'][ (int) $id ] ?? false; }
function get_the_title( $id ) { return 'Photo ' . (int) $id; }

/**
 * eBay, played, its picture upload too. An upload eBay is asked for is
 * recorded by file name and answered with a copy on eBay, unless $refuse names
 * the file. With $budget_left counting down to 0 the day's call budget is
 * spent, and the upload is refused before any call, as the real one does.
 */
class Picture_API extends Template_World_API {
	public $uploads     = array();
	public $refuse      = array();
	public $budget_left = null;
	public function upload_picture( $file_path, $name = '' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_missing', 'Image file could not be read.' );
		}
		if ( null !== $this->budget_left && $this->budget_left < 1 ) {
			return new WP_Error( 'rate_limited', 'Daily API call budget exhausted.' );
		}
		if ( null !== $this->budget_left ) {
			$this->budget_left--;
		}
		$this->uploads[] = basename( $file_path );
		return $this->refuse[ basename( $file_path ) ] ?? eps( (int) preg_replace( '/\D/', '', basename( $file_path ) ) );
	}
}
$GLOBALS['API'] = new Picture_API();
$API            = $GLOBALS['API'];
$tcg_slot       = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_slot->setAccessible( true );
}
$tcg_slot->setValue( null, $API );
// A category that needs no condition descriptors, as test-template-verify uses.
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';

$DIR = sys_get_temp_dir() . '/tcg-picture-hosting-' . getmypid();
if ( ! is_dir( $DIR ) ) {
	mkdir( $DIR, 0777, true );
}
register_shutdown_function( function () use ( $DIR ) {
	foreach ( (array) glob( $DIR . '/*' ) as $file ) {
		@unlink( $file );
	}
	@rmdir( $DIR );
} );

/** A photo's address on the website, and its copy on eBay. */
function site( $id ) { return 'https://shop.example/wp-content/uploads/2026/10/coin-' . $id . '.jpg'; }
function eps( $id ) { return 'https://i.ebayimg.com/images/g/coin' . $id . '/s-l1600.jpg'; }
function sites( array $ids ) { return array_map( 'site', $ids ); }
function epss( array $ids ) { return array_map( 'eps', $ids ); }
function files( array $ids ) { return array_map( function ( $id ) { return 'coin-' . $id . '.jpg'; }, $ids ); }

/** A product whose photos (the featured image first) each have a file here and an address on the website. */
function photographed( $product_id, array $photo_ids, array $data = array() ) {
	foreach ( $photo_ids as $id ) {
		$GLOBALS['W']['images'][ $id ] = site( $id );
		$file                          = $GLOBALS['DIR'] . '/coin-' . $id . '.jpg';
		if ( ! file_exists( $file ) ) {
			file_put_contents( $file, 'JPEG ' . $id );
		}
		$GLOBALS['W']['files'][ $id ] = $file;
	}
	return new WC_Product( $product_id, array_merge( array(
		'name'        => 'Morgan Dollar ' . $product_id,
		'description' => 'A Morgan dollar.',
		'sku'         => 'NMX-' . $product_id,
		'image_id'    => $photo_ids[0],
		'gallery'     => array_slice( $photo_ids, 1 ),
	), $data ) );
}

/** The PictureURLs an item carries. */
function pictures( $xml ) {
	preg_match_all( '#<PictureURL>(.*?)</PictureURL>#', (string) $xml, $m );
	return $m[1];
}

/** The warnings logged from row $from on. */
function warnings_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'warning' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}

/** Every item this run built or sent, by the call it went to, for the last check: no empty PictureDetails anywhere. */
$GLOBALS['ITEMS'] = array();

/** The item Verify and the preview's Check with eBay build: its photos, the uploads made, the warnings. */
function built( WC_Product $product ) {
	$GLOBALS['API']->uploads = array();
	$from                    = count( $GLOBALS['W']['log'] );
	$xml                     = item_xml( $product, push_settings( $product ) );
	$GLOBALS['ITEMS'][]      = array( 'Verify', $xml );
	return array( pictures( $xml ), $GLOBALS['API']->uploads, warnings_from( $from ) );
}

/** A push (do_push()): each eBay call with its photos, the uploads made, the warnings, the result. */
function pushed( WC_Product $product ) {
	$GLOBALS['API']->calls   = array();
	$GLOBALS['API']->uploads = array();
	$from                    = count( $GLOBALS['W']['log'] );
	$result                  = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	$calls                   = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$calls[]            = array( $call[0], pictures( $call[1] ) );
		$GLOBALS['ITEMS'][] = $call;
	}
	return array( $calls, $GLOBALS['API']->uploads, warnings_from( $from ), $result );
}

/** How many PictureDetails nodes an item carries. */
function picture_nodes( $xml ) {
	return preg_match_all( '#<PictureDetails\b#', (string) $xml );
}

/** The XML a push sent to its $n-th eBay call (from 0). */
function sent( $n ) {
	return (string) ( $GLOBALS['API']->calls[ $n ][1] ?? '' );
}

/** The one warning a push gives, word for word. */
function mixed_warning( $photo, $product, $reason ) {
	return sprintf( 'Push: photo #%d of WC #%d could not be put on eBay (%s), so this listing\'s photos are all sent from your website. eBay does not accept a mix.', $photo, $product, $reason );
}

function hosting( $on ) { update_option( 'tcgiant_sync_ebay_settings', array( 'host_images_on_ebay' => $on ? '1' : '0' ) ); }

/** The information lines about photos logged from row $from on. */
function infos_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'info' === $row[0] && false !== strpos( $row[1], 'picture' ) ) {
			$out[] = $row[1];
		}
	}
	return $out;
}

/** An imported photo kept on eBay ("Keep eBay-hosted URLs"): no file here, and its own address is eBay's. */
function kept( $id ) { return 'https://i.ebayimg.com/images/g/kept' . $id . '/s-l1600.jpg'; }
function kept_on_ebay( $id ) {
	$GLOBALS['W']['images'][ $id ] = kept( $id );
	unset( $GLOBALS['W']['files'][ $id ] );
}

/** The warning for photos left out of a listing whose other photos are on eBay, word for word. */
function left_out_warning( $product, array $why ) {
	if ( 1 === count( $why ) ) {
		return sprintf( 'Push: photo #%d of WC #%d was left out of the listing: it could not be put on eBay (%s), and the listing\'s other photos are on eBay, which does not accept a mix.', key( $why ), $product, current( $why ) );
	}
	$names  = array();
	$detail = array();
	foreach ( $why as $id => $reason ) {
		$names[]  = '#' . $id;
		$detail[] = '#' . $id . ': ' . $reason;
	}
	return sprintf( 'Push: photos %s of WC #%d were left out of the listing: they could not be put on eBay (%s), and the listing\'s other photos are on eBay, which does not accept a mix.', implode( ', ', $names ), $product, implode( '; ', $detail ) );
}

/**
 * The warning for a revise sent no photos, so that none came off its live
 * listing, word for word. It ends with when its photos change again: by
 * default, with the next push that can upload them; $then gives another
 * ending, word for word, for a photo whose file is not on this server.
 */
function unchanged_warning( $product, array $why, $then = null ) {
	if ( null === $then ) {
		$then = 1 === count( $why ) ? 'It goes on the listing with the next push that can upload it.' : 'They go on the listing with the next push that can upload them.';
	}
	if ( 1 === count( $why ) ) {
		return sprintf( 'Push: photo #%d of WC #%d could not be put on eBay (%s), so the listing\'s photos were not changed this time: its other photos are on eBay, which does not accept a mix. %s', key( $why ), $product, current( $why ), $then );
	}
	$names  = array();
	$detail = array();
	foreach ( $why as $id => $reason ) {
		$names[]  = '#' . $id;
		$detail[] = '#' . $id . ': ' . $reason;
	}
	return sprintf( 'Push: photos %s of WC #%d could not be put on eBay (%s), so the listing\'s photos were not changed this time: its other photos are on eBay, which does not accept a mix. %s', implode( ', ', $names ), $product, implode( '; ', $detail ), $then );
}

/** The warning for a product with more photos than a listing is sent, word for word. */
function past_cap_warning( $product, $n ) {
	if ( 1 === $n ) {
		return sprintf( 'Push: WC #%d has more photos than the 24 eBay takes in one listing, so the one after the first 24 was not sent.', $product );
	}
	return sprintf( 'Push: WC #%d has more photos than the 24 eBay takes in one listing, so the %d after the first 24 were not sent.', $product, $n );
}

/** The warning for photo addresses too long together for eBay, word for word. */
function too_long_warning( $product, $n, $sent ) {
	if ( 1 === $n ) {
		return sprintf( 'Push: the photo addresses of WC #%d are too long together for eBay, which takes about 4,000 characters of them in one listing, so the one after the first %d was not sent.', $product, $sent );
	}
	return sprintf( 'Push: the photo addresses of WC #%d are too long together for eBay, which takes about 4,000 characters of them in one listing, so the %d after the first %d were not sent.', $product, $n, $sent );
}

/** The photos' addresses on the website as they are now set, in order. */
function sites_of( array $ids ) {
	return array_map( function ( $id ) { return $GLOBALS['W']['images'][ $id ]; }, $ids );
}

/** A product with a live listing on eBay, of the format given: its pushes revise it. */
function live( $product_id, $format = 'FixedPriceItem' ) {
	update_post_meta( $product_id, '_ebay_item_id', '1100000' . $product_id );
	update_post_meta( $product_id, '_ebay_listing_type', $format );
	update_post_meta( $product_id, '_ebay_listing_status', 'Active' );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWITH \"HOST LISTING IMAGES ON EBAY?\" ON\n" . str_repeat( '=', 124 ) . "\n";

hosting( true );

$all = photographed( 501, array( 11, 12, 13, 14 ) );
list( $pics, $uploads, $warned ) = built( $all );
check( 'every photo uploads: all four go as their eBay copies', $pics, epss( array( 11, 12, 13, 14 ) ) );
check( '  each uploaded once, in order, with nothing logged', array( $uploads, $warned ), array( files( array( 11, 12, 13, 14 ) ), array() ) );
check( '  and each copy cached for the next push', array( get_post_meta( 11, '_tcgiant_eps_url', true ), get_post_meta( 14, '_tcgiant_eps_url', true ) ), array( eps( 11 ), eps( 14 ) ) );
list( $pics, $uploads ) = built( $all );
check( 'the next push sends the cached copies, uploading nothing', array( $pics, $uploads ), array( epss( array( 11, 12, 13, 14 ) ), array() ) );

// The second of four cannot be put on eBay.
$second = photographed( 502, array( 21, 22, 23, 24 ) );
$API->refuse['coin-22.jpg'] = new WP_Error( 'upload_failed', 'The picture file is corrupt.' );
list( $pics, $uploads, $warned ) = built( $second );
check( 'the 2nd of 4 cannot be put on eBay: all four go from the website, none from eBay', $pics, sites( array( 21, 22, 23, 24 ) ) );
check( '  no upload is tried after it', $uploads, files( array( 21, 22 ) ) );
check( '  exactly one warning, naming the photo, the product and eBay\'s reason', $warned, array( mixed_warning( 22, 502, 'The picture file is corrupt' ) ) );
check( '  the first one\'s copy stays cached for later pushes', get_post_meta( 21, '_tcgiant_eps_url', true ), eps( 21 ) );
unset( $API->refuse['coin-22.jpg'] );
list( $pics, $uploads, $warned ) = built( $second );
check( 'pushed again once it uploads: all four eBay copies, the first from the cache', array( $pics, $uploads, $warned ), array( epss( array( 21, 22, 23, 24 ) ), files( array( 22, 23, 24 ) ), array() ) );

// A file that is not on this server: an offloader took it, or it was deleted.
$cdn = photographed( 503, array( 31, 32, 33 ) );
unlink( $GLOBALS['W']['files'][32] );
list( $pics, $uploads, $warned ) = built( $cdn );
check( 'a photo whose file is not on this server: all go from the website', $pics, sites( array( 31, 32, 33 ) ) );
check( '  nothing uploaded for it or after it', $uploads, files( array( 31 ) ) );
check( '  and the warning, which there never was, says so plainly', $warned, array( mixed_warning( 32, 503, 'its file is not on this server' ) ) );
$offloaded = photographed( 504, array( 41, 42 ) );
unlink( $GLOBALS['W']['files'][41] );
unlink( $GLOBALS['W']['files'][42] );
list( $pics, $uploads, $warned ) = built( $offloaded );
check( 'every file offloaded to a CDN: website addresses, no upload, one warning naming the first', array( $pics, $uploads, $warned ), array( sites( array( 41, 42 ) ), array(), array( mixed_warning( 41, 504, 'its file is not on this server' ) ) ) );

// The cache holds eBay copies, and a new upload fails.
$cached = photographed( 505, array( 51, 52, 53 ) );
foreach ( array( 51, 52 ) as $id ) {
	update_post_meta( $id, '_tcgiant_eps_url', eps( $id ) );
	update_post_meta( $id, '_tcgiant_eps_key', (string) filemtime( $GLOBALS['W']['files'][ $id ] ) );
	update_post_meta( $id, '_tcgiant_eps_time', time() - 5 * DAY_IN_SECONDS );
}
$API->refuse['coin-53.jpg'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' );
list( $pics, $uploads, $warned ) = built( $cached );
check( 'two photos with fresh eBay copies cached, the third\'s upload fails: all three from the website', $pics, sites( array( 51, 52, 53 ) ) );
check( '  only the third was uploaded', $uploads, files( array( 53 ) ) );
check( '  one warning, with the reason', $warned, array( mixed_warning( 53, 505, 'cURL error 28: Operation timed out after 60001 milliseconds' ) ) );
check( '  and the cached copies are kept', array( get_post_meta( 51, '_tcgiant_eps_url', true ), get_post_meta( 52, '_tcgiant_eps_url', true ) ), array( eps( 51 ), eps( 52 ) ) );

// The day's call budget runs out part way: the budget refusal (rate_limited).
$budget = photographed( 506, array( 61, 62, 63 ) );
$API->budget_left = 1;
list( $pics, $uploads, $warned ) = built( $budget );
check( 'the day\'s call budget runs out after the first photo (rate_limited): all from the website', $pics, sites( array( 61, 62, 63 ) ) );
check( '  one upload made, one warning, naming the budget', array( $uploads, $warned ), array( files( array( 61 ) ), array( mixed_warning( 62, 506, 'Daily API call budget exhausted' ) ) ) );
$API->budget_left = null;
$api_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-api.php' );
check( '  the refusal played is the real upload_picture()\'s', false !== strpos( $api_src, "return new WP_Error( 'rate_limited', 'Daily API call budget exhausted.' );" ), true );

// Nothing to send at all: no copy on eBay, and no address on the website either.
$stale = photographed( 507, array( 71, 72, 73 ) );
unset( $GLOBALS['W']['images'][72], $GLOBALS['W']['files'][72] );
list( $pics, $uploads, $warned ) = built( $stale );
check( 'a gallery photo since deleted (no file, no address): left out, the rest still eBay copies, as before', array( $pics, $warned ), array( epss( array( 71, 73 ) ), array() ) );
$no_address = photographed( 508, array( 75, 76, 77 ) );
unset( $GLOBALS['W']['images'][76] );
$API->refuse['coin-76.jpg'] = new WP_Error( 'upload_failed', 'Not an image.' );
$from = count( $GLOBALS['W']['log'] );
list( $pics, $uploads, $warned ) = built( $no_address );
check( 'a photo with no website address whose upload fails: left out, as before; the rest eBay copies', array( $pics, $uploads, $warned ), array( epss( array( 75, 77 ) ), files( array( 75, 76, 77 ) ), array() ) );
check( '  and 3.27.7\'s line says so, as information, naming the photo', infos_from( $from ), array( 'eBay picture upload failed for attachment #76 (Not an image). It has no address on this site either, so it is left out of the listing.' ) );
$from = count( $GLOBALS['W']['log'] );
built( $stale );
check( '  one since deleted, whose upload was never tried, says nothing', infos_from( $from ), array() );
unset( $API->refuse['coin-76.jpg'] );

$many = photographed( 509, range( 3101, 3130 ) );
list( $pics, $uploads, $warned ) = built( $many );
check( 'thirty photos: twenty-four go, eBay\'s limit, and only those are uploaded', array( count( $pics ), $uploads ), array( 24, files( range( 3101, 3124 ) ) ) );
check( '  one warning, saying 6 were not sent', $warned, array( past_cap_warning( 509, 6 ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWITH IT OFF\n" . str_repeat( '=', 124 ) . "\n";

hosting( false );
list( $pics, $uploads, $warned ) = built( $cached );
check( 'website addresses, as 3.27.7 sent them, though eBay copies are cached', $pics, sites( array( 51, 52, 53 ) ) );
check( '  no upload tried, nothing logged', array( $uploads, $warned ), array( array(), array() ) );
update_option( 'tcgiant_sync_ebay_settings', array() );
list( $pics, $uploads ) = built( $all );
check( 'never set: the same', array( $pics, $uploads ), array( sites( array( 11, 12, 13, 14 ) ), array() ) );
list( $pics, $uploads, $warned ) = built( $many );
check( '  twenty-four of thirty, from the website, nothing uploaded; the same warning', array( $pics, $uploads, $warned ), array( sites( range( 3101, 3124 ) ), array(), array( past_cap_warning( 509, 6 ) ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO ALREADY ON EBAY: THE LISTING GOES ALL ON EBAY, WHATEVER THE SETTING\n" . str_repeat( '=', 124 ) . "\n";

hosting( true );

// A. The featured photo kept on eBay at import, the gallery the shop's own.
$a = photographed( 601, array( 201, 202, 203 ) );
kept_on_ebay( 201 );
list( $pics, $uploads, $warned ) = built( $a );
check( 'A. featured kept on eBay, gallery the shop\'s: all on eBay, the kept one as its own address', $pics, array( kept( 201 ), eps( 202 ), eps( 203 ) ) );
check( '  the shop\'s two uploaded, nothing logged', array( $uploads, $warned ), array( files( array( 202, 203 ) ), array() ) );

// B. The second photo kept on eBay.
$b = photographed( 602, array( 211, 212, 213 ) );
kept_on_ebay( 212 );
list( $pics, $uploads, $warned ) = built( $b );
check( 'B. the 2nd kept on eBay: all on eBay, in order', array( $pics, $uploads, $warned ), array( array( eps( 211 ), kept( 212 ), eps( 213 ) ), files( array( 211, 213 ) ), array() ) );

// C. Every photo kept on eBay: nothing to upload, nothing to say (finding 2).
$c = photographed( 603, array( 221, 222, 223 ) );
kept_on_ebay( 221 );
kept_on_ebay( 222 );
kept_on_ebay( 223 );
list( $pics, $uploads, $warned ) = built( $c );
check( 'C. every photo kept on eBay: their own addresses, no upload, no warning', array( $pics, $uploads, $warned ), array( array( kept( 221 ), kept( 222 ), kept( 223 ) ), array(), array() ) );
hosting( false );
list( $pics, $uploads, $warned ) = built( $c );
check( '  the setting off: the same, as 3.27.7 sent them', array( $pics, $uploads, $warned ), array( array( kept( 221 ), kept( 222 ), kept( 223 ) ), array(), array() ) );

// D. The setting off: 3.27.7 sent the kept photo's address beside the shop's.
$d = photographed( 604, array( 241, 242, 243 ) );
kept_on_ebay( 241 );
list( $pics, $uploads, $warned ) = built( $d );
check( 'D. the setting off, featured kept on eBay: still all on eBay, eBay refuses the mix outright', $pics, array( kept( 241 ), eps( 242 ), eps( 243 ) ) );
check( '  the shop\'s two uploaded with the setting off, nothing logged', array( $uploads, $warned ), array( files( array( 242, 243 ) ), array() ) );
list( $pics, $uploads ) = built( $d );
check( '  and the next push takes them from the cache', array( $pics, $uploads ), array( array( kept( 241 ), eps( 242 ), eps( 243 ) ), array() ) );

// K. One of the shop's photos cannot be put on eBay: left out, said once.
hosting( true );
$k = photographed( 605, array( 251, 252, 253 ) );
kept_on_ebay( 251 );
$API->refuse['coin-253.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $pics, $uploads, $warned ) = built( $k );
check( 'K. featured kept on eBay, the 3rd\'s upload refused: the 3rd left out, the rest on eBay', array( $pics, $uploads ), array( array( kept( 251 ), eps( 252 ) ), files( array( 252, 253 ) ) ) );
check( '  one warning, naming the photo, the product and why', $warned, array( left_out_warning( 605, array( 253 => 'eBay refused the file' ) ) ) );
unset( $API->refuse['coin-253.jpg'] );
hosting( false );
$k2 = photographed( 606, array( 261, 262, 263, 264 ) );
kept_on_ebay( 261 );
unlink( $GLOBALS['W']['files'][262] );
$API->refuse['coin-263.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $pics, $uploads, $warned ) = built( $k2 );
check( '  the setting off, one offloaded (no file here) and one refused: both left out', array( $pics, $uploads ), array( array( kept( 261 ), eps( 264 ) ), files( array( 263, 264 ) ) ) );
check( '  still one warning, naming each and why', $warned, array( left_out_warning( 606, array( 262 => 'its file is not on this server', 263 => 'eBay refused the file' ) ) ) );
unset( $API->refuse['coin-263.jpg'] );

// The cap counts what goes: a photo left out makes room for the next.
hosting( true );
$room = photographed( 607, range( 3301, 3330 ) );
kept_on_ebay( 3301 );
$API->refuse['coin-3302.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $pics, $uploads, $warned ) = built( $room );
check( 'thirty photos, the 1st kept on eBay and the 2nd refused: twenty-four go, the 25th uploaded, the 26th not', array( count( $pics ), $pics[0], in_array( eps( 3325 ), $pics, true ), in_array( eps( 3326 ), $pics, true ), $uploads ), array( 24, kept( 3301 ), true, false, files( range( 3302, 3325 ) ) ) );
check( '  the left-out warning, then the 5 past the limit', $warned, array( left_out_warning( 607, array( 3302 => 'eBay refused the file' ) ), past_cap_warning( 607, 5 ) ) );
unset( $API->refuse['coin-3302.jpg'] );

// E. Numismax's push with a photo kept on eBay: the ended GTC as a 10-day
// auction, its item built twice, the shop's photo that fails tried once.
$e = photographed( 608, array( 271, 272, 273 ), array( 'stock' => 2 ) );
kept_on_ebay( 271 );
update_post_meta( 608, '_ebay_item_id', '110000000608' );
update_post_meta( 608, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 608, '_ebay_listing_status', 'Ended' );
update_post_meta( 608, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 608, '_ebay_export_listing_duration', 'Days_10' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000608', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ), 'Site' => 'US' ) );
$API->refuse['coin-273.jpg']          = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned, $result ) = pushed( $e );
check( 'E. Numismax\'s push, featured kept on eBay: relisted; the revise sent no photos, the new listing all on eBay', array( is_array( $result ) ? $result['action'] : $result->get_error_message(), $calls ), array( 'relisted', array( array( 'ReviseFixedPriceItem', array() ), array( 'GetItem', array() ), array( 'AddItem', array( kept( 271 ), eps( 272 ) ) ) ) ) );
check( '  no PictureDetails in the revise, one in the new listing', array( picture_nodes( sent( 0 ) ), picture_nodes( sent( 2 ) ) ), array( 0, 1 ) );
check( '  the item built twice: each photo uploaded once, the warning given once', array( $uploads, $warned ), array( files( array( 272, 273 ) ), array( left_out_warning( 608, array( 273 => 'eBay refused the file' ) ) ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $API->refuse['coin-273.jpg'] );
$kept_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-image-localizer.php' );
check( '  a kept photo is played as the plugin keeps one: its address from _tcgiant_external_url, no file', array( false !== strpos( $kept_src, "update_post_meta( \$attachment_id, '_tcgiant_external_url', \$url );" ), false !== strpos( $kept_src, "add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 10, 2 );" ) ), array( true, true ) );

// Only the photos a listing would send decide (decision 44): a kept photo
// past the twenty-fourth cannot be in it, so it cannot make a mix.
hosting( false );
$late = photographed( 610, range( 3401, 3430 ) );
kept_on_ebay( 3426 );
list( $pics, $uploads, $warned ) = built( $late );
check( 'the only photo kept on eBay is the 26th, the setting off: the first 24 website addresses, nothing uploaded, 6 past the limit', array( $pics, $uploads, $warned ), array( sites( range( 3401, 3424 ) ), array(), array( past_cap_warning( 610, 6 ) ) ) );

// eBay's own addresses are told by their host alone.
hosting( false );
$hosts = photographed( 609, array( 281, 282 ) );
$GLOBALS['W']['images'][281] = 'https://shop.example/wp-content/uploads/ebayimg.com/coin-281.jpg';
$GLOBALS['W']['images'][282] = 'https://ebayimg.com.shop.example/coin-282.jpg';
list( $pics, $uploads ) = built( $hosts );
check( 'an address that only mentions ebayimg.com is not eBay\'s: the setting off sends them as they are', array( $pics, $uploads ), array( array( 'https://shop.example/wp-content/uploads/ebayimg.com/coin-281.jpg', 'https://ebayimg.com.shop.example/coin-282.jpg' ), array() ) );
$GLOBALS['W']['images'][281] = 'HTTPS://I.EBAYIMG.COM/images/g/x/s-l1600.jpg';
list( $pics, $uploads ) = built( $hosts );
check( '  eBay\'s, in capitals: on eBay, so the other goes on eBay too', array( $pics, $uploads ), array( array( 'HTTPS://I.EBAYIMG.COM/images/g/x/s-l1600.jpg', eps( 282 ) ), files( array( 282 ) ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY CALL THAT SENDS PHOTOS GOES THROUGH THE SAME RULE\n" . str_repeat( '=', 124 ) . "\n";

hosting( true );

// A new listing: AddFixedPriceItem (and AddItem for an auction).
$new = photographed( 520, array( 81, 82, 83 ), array( 'stock' => 3 ) );
$API->refuse['coin-82.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned ) = pushed( $new );
check( 'a push of a new listing: AddFixedPriceItem, every photo from the website', $calls, array( array( 'AddFixedPriceItem', sites( array( 81, 82, 83 ) ) ) ) );
check( '  one warning', $warned, array( mixed_warning( 82, 520, 'eBay refused the file' ) ) );
update_post_meta( 520, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 520, '_ebay_export_listing_duration', 'Days_10' );
list( $calls ) = pushed( $new );
check( '  an auction: AddItem, the same', $calls, array( array( 'AddItem', sites( array( 81, 82, 83 ) ) ) ) );
delete_post_meta( 520, '_ebay_export_listing_type' );
delete_post_meta( 520, '_ebay_export_listing_duration' );

// A live listing: ReviseFixedPriceItem (ReviseItem for an auction).
update_post_meta( 520, '_ebay_item_id', '110000000520' );
update_post_meta( 520, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 520, '_ebay_listing_status', 'Active' );
list( $calls ) = pushed( $new );
check( 'a push that revises its listing: ReviseFixedPriceItem, every photo from the website', $calls, array( array( 'ReviseFixedPriceItem', sites( array( 81, 82, 83 ) ) ) ) );
update_post_meta( 520, '_ebay_listing_type', 'Chinese' );
list( $calls ) = pushed( $new );
check( '  an auction\'s: ReviseItem, the same', $calls, array( array( 'ReviseItem', sites( array( 81, 82, 83 ) ) ) ) );
unset( $API->refuse['coin-82.jpg'] );
list( $calls ) = pushed( $new );
check( '  and once every photo uploads, eBay copies', $calls, array( array( 'ReviseItem', epss( array( 81, 82, 83 ) ) ) ) );

// Numismax: an ended GTC listing, set to a 10-day auction and pushed. The
// revise is refused (ended), eBay is asked what sold, and the item is built
// again for the new listing.
$ended = photographed( 530, array( 91, 92, 93, 94 ), array( 'stock' => 2 ) );
update_post_meta( 530, '_ebay_item_id', '110000000530' );
update_post_meta( 530, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 530, '_ebay_listing_status', 'Ended' );
update_post_meta( 530, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 530, '_ebay_export_listing_duration', 'Days_10' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000530', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ), 'Site' => 'US' ) );
$API->refuse['coin-92.jpg']           = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned, $result ) = pushed( $ended );
check( 'an ended listing pushed as a 10-day auction: the new listing is made', array( is_array( $result ) ? $result['action'] : $result->get_error_message() ), array( 'relisted' ) );
check( '  ReviseFixedPriceItem, GetItem, then AddItem, every photo from the website in both items', $calls, array( array( 'ReviseFixedPriceItem', sites( array( 91, 92, 93, 94 ) ) ), array( 'GetItem', array() ), array( 'AddItem', sites( array( 91, 92, 93, 94 ) ) ) ) );
check( '  the item built twice, the photo that failed uploaded once, and said once', array( $uploads, $warned ), array( files( array( 91, 92 ) ), array( mixed_warning( 92, 530, 'eBay refused the file' ) ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $API->refuse['coin-92.jpg'] );

// One exporter runs many pushes: each decides its photos afresh.
$retry = photographed( 540, array( 95, 96 ) );
$API->refuse['coin-96.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
pushed( $retry );
unset( $API->refuse['coin-96.jpg'] );
list( $calls, $uploads ) = pushed( $retry );
check( 'the next push of the same product, once its photo uploads: eBay copies, the first from the cache', array( $calls, $uploads ), array( array( array( 'AddFixedPriceItem', epss( array( 95, 96 ) ) ) ), files( array( 96 ) ) ) );
$checked = photographed( 541, array( 97, 98 ) );
$API->refuse['coin-98.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
pushed( $checked );
unset( $API->refuse['coin-98.jpg'] );
list( $pics, $uploads ) = built( $checked );
check( 'Verify after that push, once its photo uploads: eBay copies', array( $pics, $uploads ), array( epss( array( 97, 98 ) ), files( array( 98 ) ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PHOTO THAT CANNOT BE PUT ON EBAY NEVER COMES OFF A LIVE LISTING\n" . str_repeat( '=', 124 ) . "\n";

// Each listing here has a photo kept on eBay, so it goes all on eBay, and one
// of the shop's cannot be put there. Left out of a revise's PictureDetails it
// would come off the live listing. eBay's Revise calls change only what they
// are sent, so that revise is sent none, and the listing keeps its own.
hosting( true );

// The day's calls past the 80% ceiling: the upload is refused before any call.
$ceiling = photographed( 701, array( 301, 302, 303 ) );
kept_on_ebay( 301 );
live( 701 );
$API->budget_left = 1;
list( $calls, $uploads, $warned, $result ) = pushed( $ceiling );
$held_xml = sent( 0 );
check( 'a revise, the 3rd photo refused past the call ceiling (rate_limited): ReviseFixedPriceItem with no PictureDetails', array( $calls, picture_nodes( $held_xml ) ), array( array( array( 'ReviseFixedPriceItem', array() ) ), 0 ) );
check( '  one warning: the listing\'s photos not changed, the photo, why, and that the next push that can upload it puts it on', $warned, array( unchanged_warning( 701, array( 303 => 'Daily API call budget exhausted' ) ) ) );
check( '  everything else goes as normal: the listing, title, price, quantity and description; the push succeeds', array( false !== strpos( $held_xml, '<ItemID>1100000701</ItemID>' ), false !== strpos( $held_xml, '<Title>Morgan Dollar 701</Title>' ), false !== strpos( $held_xml, '<StartPrice>' ), false !== strpos( $held_xml, '<Quantity>' ), false !== strpos( $held_xml, 'A Morgan dollar.' ), is_array( $result ) ? $result['action'] : $result->get_error_message() ), array( true, true, true, true, true, 'updated' ) );
check( '  the 2nd uploaded, and cached for the next push', array( $uploads, get_post_meta( 302, '_tcgiant_eps_url', true ) ), array( files( array( 302 ) ), eps( 302 ) ) );
$API->budget_left = null;
list( $calls, $uploads, $warned ) = pushed( $ceiling );
check( 'the next push, which can upload it: ReviseFixedPriceItem all on eBay, the 2nd from the cache, nothing said', array( $calls, $uploads, $warned ), array( array( array( 'ReviseFixedPriceItem', array( kept( 301 ), eps( 302 ), eps( 303 ) ) ) ), files( array( 303 ) ), array() ) );
check( '  its item is the one before but for its PictureDetails', preg_replace( '#<PictureDetails>.*?</PictureDetails>\n#s', '', sent( 0 ) ), $held_xml );

// An offloader has moved a file off this server.
$moved = photographed( 702, array( 311, 312, 313 ) );
kept_on_ebay( 311 );
live( 702 );
unlink( $GLOBALS['W']['files'][312] );
list( $calls, $uploads, $warned, $result ) = pushed( $moved );
check( 'a revise, the 2nd photo\'s file offloaded from this server: ReviseFixedPriceItem with no PictureDetails', array( $calls, picture_nodes( sent( 0 ) ) ), array( array( array( 'ReviseFixedPriceItem', array() ) ), 0 ) );
check( '  one warning, its file not on this server; the 3rd uploaded; the push succeeds', array( $warned, $uploads, is_array( $result ) ? $result['action'] : $result->get_error_message() ), array( array( unchanged_warning( 702, array( 312 => 'its file is not on this server' ), 'The listing\'s photos stay as they are until the file of #312 is back on this server or that photo is taken off the product.' ) ), files( array( 313 ) ), 'updated' ) );
check( '  no promise of the next push: no push can upload a file that is not here', false === strpos( (string) ( $warned[0] ?? '' ), 'next push' ), true );
file_put_contents( $DIR . '/coin-312.jpg', 'JPEG 312' );
list( $calls, $uploads, $warned ) = pushed( $moved );
check( '  the file back: the next push decides afresh, all on eBay, the 3rd from the cache', array( $calls, $uploads, $warned ), array( array( array( 'ReviseFixedPriceItem', array( kept( 311 ), eps( 312 ), eps( 313 ) ) ) ), files( array( 312 ) ), array() ) );
$two_moved = photographed( 713, array( 386, 387, 388 ) );
kept_on_ebay( 386 );
live( 713 );
unlink( $GLOBALS['W']['files'][387] );
unlink( $GLOBALS['W']['files'][388] );
list( $calls, $uploads, $warned ) = pushed( $two_moved );
check( '  two files offloaded: no PictureDetails, one warning naming both files, until they are back or off the product', array( $calls, picture_nodes( sent( 0 ) ), $uploads, $warned ), array( array( array( 'ReviseFixedPriceItem', array() ) ), 0, array(), array( unchanged_warning( 713, array( 387 => 'its file is not on this server', 388 => 'its file is not on this server' ), 'The listing\'s photos stay as they are until the files of #387, #388 are back on this server or those photos are taken off the product.' ) ) ) );

// A staging copy: every upload is refused before any call, and so is the
// revise itself, as trading_api_request() refuses a write there.
$staging = photographed( 703, array( 321, 322, 323 ) );
kept_on_ebay( 321 );
live( 703 );
// 3.28.0's refusal, as TCGiant_Sync_Environment::write_block() gives it. (On
// a real copy the exporter does not ask for the upload at all:
// test-picture-relist.php's staging run.)
$staged                               = new WP_Error( 'staging_blocked', TCGiant_Sync_Environment::message( 'wp-config' ) );
$staged_why                           = rtrim( TCGiant_Sync_Environment::message( 'wp-config' ), '. ' );
$API->refuse['coin-322.jpg']          = $staged;
$API->refuse['coin-323.jpg']          = $staged;
$API->answers['ReviseFixedPriceItem'] = $staged;
list( $calls, $uploads, $warned, $result ) = pushed( $staging );
check( 'a revise on a staging copy, both of the shop\'s photos refused: ReviseFixedPriceItem with no PictureDetails', array( $calls, picture_nodes( sent( 0 ) ) ), array( array( array( 'ReviseFixedPriceItem', array() ) ), 0 ) );
check( '  one warning naming both, the revise refused too: the push fails with that refusal', array( $warned, is_wp_error( $result ) ? $result->get_error_code() : '' ), array( array( unchanged_warning( 703, array( 322 => $staged_why, 323 => $staged_why ) ) ), 'staging_blocked' ) );
$env_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-environment.php' );
check( '  the refusal played is the real one: write_block()\'s, asked by upload_picture() and trading_api_request()', array( substr_count( $api_src, "\$blocked = \$this->blocked_write( 'UploadSiteHostedPictures' );" ), substr_count( $api_src, '$blocked = $this->blocked_write( $call_name );' ), substr_count( $env_src, "return new WP_Error( 'staging_blocked', self::message( \$reason ), array(" ) ), array( 1, 1, 1 ) );
unset( $API->refuse['coin-322.jpg'], $API->refuse['coin-323.jpg'], $API->answers['ReviseFixedPriceItem'] );

// An auction's listing: ReviseItem. One file offloaded and one upload refused.
$auction = photographed( 704, array( 331, 332, 333, 334 ) );
kept_on_ebay( 331 );
live( 704, 'Chinese' );
unlink( $GLOBALS['W']['files'][332] );
$API->refuse['coin-333.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned ) = pushed( $auction );
check( 'an auction\'s revise, one file offloaded and one upload refused: ReviseItem with no PictureDetails', array( $calls, picture_nodes( sent( 0 ) ) ), array( array( array( 'ReviseItem', array() ) ), 0 ) );
check( '  still one warning, naming each and why; the photos stay as they are until the offloaded one\'s file is back', array( $warned, $uploads ), array( array( unchanged_warning( 704, array( 332 => 'its file is not on this server', 333 => 'eBay refused the file' ), 'The listing\'s photos stay as they are until the file of #332 is back on this server or that photo is taken off the product.' ) ), files( array( 333, 334 ) ) ) );
unset( $API->refuse['coin-333.jpg'] );

// Every photo goes on eBay: the revise is as it was.
hosting( false );
$every = photographed( 705, array( 341, 342, 343 ) );
kept_on_ebay( 341 );
live( 705 );
list( $calls, $uploads, $warned ) = pushed( $every );
check( 'a revise with a photo on eBay and every upload working (the setting off): all on eBay, nothing said', array( $calls, $uploads, $warned, picture_nodes( sent( 0 ) ) ), array( array( array( 'ReviseFixedPriceItem', array( kept( 341 ), eps( 342 ), eps( 343 ) ) ) ), files( array( 342, 343 ) ), array(), 1 ) );

// A new listing goes without the photo, as before: AddItem, AddFixedPriceItem.
hosting( true );
$add = photographed( 706, array( 351, 352, 353 ), array( 'stock' => 2 ) );
kept_on_ebay( 351 );
update_post_meta( 706, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 706, '_ebay_export_listing_duration', 'Days_10' );
$API->budget_left = 1;
list( $calls, $uploads, $warned, $result ) = pushed( $add );
check( 'a new listing, the 3rd refused past the call ceiling: AddItem goes without it, the rest on eBay, as before', array( $calls, picture_nodes( sent( 0 ) ) ), array( array( array( 'AddItem', array( kept( 351 ), eps( 352 ) ) ) ), 1 ) );
check( '  the left-out warning, once; the listing is made', array( $warned, is_array( $result ) ? $result['action'] : $result->get_error_message() ), array( array( left_out_warning( 706, array( 353 => 'Daily API call budget exhausted' ) ) ), 'created' ) );
$API->budget_left = null;
$add_fp = photographed( 707, array( 361, 362 ) );
kept_on_ebay( 361 );
unlink( $GLOBALS['W']['files'][362] );
list( $calls, $uploads, $warned ) = pushed( $add_fp );
check( '  AddFixedPriceItem, the 2nd offloaded: goes without it, with the left-out warning', array( $calls, $warned ), array( array( array( 'AddFixedPriceItem', array( kept( 361 ) ) ) ), array( left_out_warning( 707, array( 362 => 'its file is not on this server' ) ) ) ) );

// A new listing for an ended one, as AddItem. The revise is refused (ended),
// and the item is built again with its photos, also when eBay sold none on
// the old listing, so no stock check calls for a new build (E above: one did).
$ended_live = photographed( 708, array( 371, 372, 373 ), array( 'stock' => 2 ) );
kept_on_ebay( 371 );
update_post_meta( 708, '_ebay_item_id', '110000000708' );
update_post_meta( 708, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 708, '_ebay_listing_status', 'Ended' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000708', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'Site' => 'US' ) );
$API->refuse['coin-373.jpg']          = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned, $result ) = pushed( $ended_live );
check( 'an ended listing, eBay sold none on it: the revise sent no photos, the new listing goes without the one, as AddItem', array( is_array( $result ) ? $result['action'] : $result->get_error_message(), $calls ), array( 'relisted', array( array( 'ReviseFixedPriceItem', array() ), array( 'GetItem', array() ), array( 'AddFixedPriceItem', array( kept( 371 ), eps( 372 ) ) ) ) ) );
check( '  no PictureDetails in the revise, one in the new listing; the left-out warning once; each photo uploaded once', array( picture_nodes( sent( 0 ) ), picture_nodes( sent( 2 ) ), $warned, $uploads ), array( 0, 1, array( left_out_warning( 708, array( 373 => 'eBay refused the file' ) ) ), files( array( 372, 373 ) ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $API->refuse['coin-373.jpg'] );

// And when eBay's sales bring the stock down first: the new listing is built
// from the product read again, with its photos.
$sold_down = photographed( 712, array( 376, 377, 378 ), array( 'stock' => 3 ) );
kept_on_ebay( 376 );
update_post_meta( 712, '_ebay_item_id', '110000000712' );
update_post_meta( 712, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 712, '_ebay_listing_status', 'Ended' );
$GLOBALS['SOLD_DOWN'][712]            = true;
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000712', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ), 'Site' => 'US' ) );
$API->refuse['coin-378.jpg']          = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned, $result ) = pushed( $sold_down );
check( 'an ended listing that sold 1 of 3: the new listing offers the 2 left, its photos less the one, as AddItem', array( is_array( $result ) ? $result['action'] : $result->get_error_message(), $calls, false !== strpos( sent( 2 ), '<Quantity>2</Quantity>' ) ), array( 'relisted', array( array( 'ReviseFixedPriceItem', array() ), array( 'GetItem', array() ), array( 'AddFixedPriceItem', array( kept( 376 ), eps( 377 ) ) ) ), true ) );
check( '  the stock line, then the left-out warning, once', array( count( $warned ), 0 === strpos( (string) ( $warned[0] ?? '' ), 'WC #712 stock lowered from 3 to 2 before listing it again' ), $warned[1] ?? '' ), array( 2, true, left_out_warning( 712, array( 378 => 'eBay refused the file' ) ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $API->refuse['coin-378.jpg'], $GLOBALS['SOLD_DOWN'][712] );

// The same with no photo held back (no photo on eBay, the setting off): the
// stock check alone has the item built again, from the product read again.
hosting( false );
$sold_plain = photographed( 714, array( 384, 385 ), array( 'stock' => 3 ) );
update_post_meta( 714, '_ebay_item_id', '110000000714' );
update_post_meta( 714, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 714, '_ebay_listing_status', 'Ended' );
$GLOBALS['SOLD_DOWN'][714]            = true;
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000714', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ), 'Site' => 'US' ) );
list( $calls, $uploads, $warned, $result ) = pushed( $sold_plain );
check( 'an ended listing that sold 1 of 3, no photo held back: the new listing offers the 2 left, never the 3 it had', array( is_array( $result ) ? $result['action'] : $result->get_error_message(), $calls, preg_match( '#<Quantity>(\d+)</Quantity>#', sent( 2 ), $q ) ? $q[1] : '' ), array( 'relisted', array( array( 'ReviseFixedPriceItem', sites( array( 384, 385 ) ) ), array( 'GetItem', array() ), array( 'AddFixedPriceItem', sites( array( 384, 385 ) ) ) ), '2' ) );
check( '  only the stock line, nothing about photos, nothing uploaded', array( count( $warned ), 0 === strpos( (string) ( $warned[0] ?? '' ), 'WC #714 stock lowered from 3 to 2 before listing it again' ), $uploads ), array( 1, true, array() ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $GLOBALS['SOLD_DOWN'][714] );
hosting( true );

// Verify and Check with eBay check an add, so their item is a new listing's,
// for a product with a live listing too.
$verified = photographed( 709, array( 381, 382, 383 ) );
kept_on_ebay( 381 );
live( 709 );
$API->refuse['coin-383.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $pics, $uploads, $warned ) = built( $verified );
check( 'Verify of a product with a live listing checks an add: the photo left out, the rest on eBay, the left-out warning', array( $pics, $warned ), array( array( kept( 381 ), eps( 382 ) ), array( left_out_warning( 709, array( 383 => 'eBay refused the file' ) ) ) ) );
unset( $API->refuse['coin-383.jpg'] );
$verify_calls = array();
foreach ( array( 'admin/class-tcgiant-sync-admin.php', 'includes/class-tcgiant-sync-template-admin.php' ) as $file ) {
	$src            = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
	$verify_calls[] = array( substr_count( $src, '->verify_add_fixed_price_item( ' ), substr_count( $src, '->verify_add_item( ' ), substr_count( $src, '->revise_' ) );
}
check( '  Verify and Check with eBay call VerifyAddFixedPriceItem or VerifyAddItem, never a Revise call', $verify_calls, array( array( 1, 1, 0 ), array( 1, 1, 0 ) ) );

// No photo on eBay (cases 1 and 2): a revise never leaves one out, and is as it was.
hosting( false );
$plain = photographed( 710, array( 391, 392 ) );
live( 710 );
list( $calls, $uploads, $warned ) = pushed( $plain );
check( 'no photo on eBay, the setting off: the revise sends the website addresses, as 3.27.7', array( $calls, $uploads, $warned ), array( array( array( 'ReviseFixedPriceItem', sites( array( 391, 392 ) ) ) ), array(), array() ) );
hosting( true );
$API->refuse['coin-392.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned ) = pushed( $plain );
check( '  the setting on, an upload refused: every photo from the website, with that case\'s own warning', array( $calls, $warned ), array( array( array( 'ReviseFixedPriceItem', sites( array( 391, 392 ) ) ) ), array( mixed_warning( 392, 710, 'eBay refused the file' ) ) ) );
unset( $API->refuse['coin-392.jpg'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nUP TO 24 PHOTOS A LISTING, AND NO MORE THAN EBAY TAKES\n" . str_repeat( '=', 124 ) . "\n";

// eBay takes up to 24 photos a listing (PictureDetails); until now a push
// sent 12 and dropped the rest without a word.
hosting( false );
$twenty = photographed( 720, range( 3501, 3520 ) );
list( $calls, $uploads, $warned ) = pushed( $twenty );
check( 'twenty photos: AddFixedPriceItem sends all twenty, nothing said', array( $calls, $warned ), array( array( array( 'AddFixedPriceItem', sites( range( 3501, 3520 ) ) ) ), array() ) );
$exactly = photographed( 721, range( 3531, 3554 ) );
list( $pics, $uploads, $warned ) = built( $exactly );
check( '  twenty-four: all go, nothing said', array( count( $pics ), $warned ), array( 24, array() ) );
$thirty = photographed( 722, range( 3601, 3630 ) );
list( $calls, $uploads, $warned ) = pushed( $thirty );
check( 'thirty photos: AddFixedPriceItem sends the first twenty-four', $calls, array( array( 'AddFixedPriceItem', sites( range( 3601, 3624 ) ) ) ) );
check( '  one warning, saying the 6 after the first 24 were not sent', $warned, array( past_cap_warning( 722, 6 ) ) );
$one_over = photographed( 723, range( 3701, 3725 ) );
list( $pics, $uploads, $warned ) = built( $one_over );
check( 'twenty-five: twenty-four go, and the warning names the one', array( count( $pics ), $warned ), array( 24, array( past_cap_warning( 723, 1 ) ) ) );
hosting( true );
$thirty_eps = photographed( 724, range( 3801, 3831 ) );
unset( $GLOBALS['W']['images'][3831], $GLOBALS['W']['files'][3831] );
list( $calls, $uploads, $warned ) = pushed( $thirty_eps );
check( 'thirty, the setting on: twenty-four uploaded and sent as eBay copies, the other six never uploaded', array( $calls, $uploads ), array( array( array( 'AddFixedPriceItem', epss( range( 3801, 3824 ) ) ) ), files( range( 3801, 3824 ) ) ) );
check( '  six said, not counting a 31st since deleted (no address, so no photo)', $warned, array( past_cap_warning( 724, 6 ) ) );
$fallback_many = photographed( 732, range( 3851, 3880 ) );
$API->refuse['coin-3853.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned ) = pushed( $fallback_many );
check( '  thirty, the 3rd refused: the first 24 from the website, that case\'s warning, then the 6 past the limit', array( $calls, $warned ), array( array( array( 'AddFixedPriceItem', sites( range( 3851, 3874 ) ) ) ), array( mixed_warning( 3853, 732, 'eBay refused the file' ), past_cap_warning( 732, 6 ) ) ) );
unset( $API->refuse['coin-3853.jpg'] );
hosting( false );

// Said once a push, also when an ended listing's item is built twice.
$ended_many = photographed( 725, range( 3901, 3930 ), array( 'stock' => 2 ) );
update_post_meta( 725, '_ebay_item_id', '110000000725' );
update_post_meta( 725, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 725, '_ebay_listing_status', 'Ended' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000725', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'Site' => 'US' ) );
list( $calls, $uploads, $warned ) = pushed( $ended_many );
check( 'an ended listing with thirty photos: the revise and the new listing each send the first 24, said once', array( $calls, $warned ), array( array( array( 'ReviseFixedPriceItem', sites( range( 3901, 3924 ) ) ), array( 'GetItem', array() ), array( 'AddFixedPriceItem', sites( range( 3901, 3924 ) ) ) ), array( past_cap_warning( 725, 6 ) ) ) );
$sold_many = photographed( 733, range( 3951, 3980 ), array( 'stock' => 3 ) );
update_post_meta( 733, '_ebay_item_id', '110000000733' );
update_post_meta( 733, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 733, '_ebay_listing_status', 'Ended' );
$GLOBALS['SOLD_DOWN'][733] = true;
$API->answers['GetItem']   = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000733', 'Quantity' => '3', 'SellingStatus' => array( 'QuantitySold' => '1' ), 'Site' => 'US' ) );
list( $calls, $uploads, $warned ) = pushed( $sold_many );
check( '  and one that sold 1 of 3, built again from the product read again: the 6 said once, at the revise\'s build, then the stock line', array( count( $calls ), count( $warned ), $warned[0] ?? '', 0 === strpos( (string) ( $warned[1] ?? '' ), 'WC #733 stock lowered from 3 to 2' ) ), array( 3, 2, past_cap_warning( 733, 6 ), true ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $GLOBALS['SOLD_DOWN'][733] );

// A revise of a product with more photos than a listing has room for sends
// its first 24 too, with the warning: eBay holds at most 24 a listing (eBay
// Motors too), so it cannot already have more for the revise to take off.
$thirty_live = photographed( 728, range( 4201, 4230 ) );
live( 728 );
list( $calls, $uploads, $warned, $result ) = pushed( $thirty_live );
check( 'a revise of a product with 30 photos: ReviseFixedPriceItem with the first 24, the 6 said; the push succeeds', array( $calls, $warned, is_array( $result ) ? $result['action'] : $result->get_error_message() ), array( array( array( 'ReviseFixedPriceItem', sites( range( 4201, 4224 ) ) ) ), array( past_cap_warning( 728, 6 ) ), 'updated' ) );
$thirty_live->data['gallery'] = range( 4202, 4220 );
list( $calls, $uploads, $warned ) = pushed( $thirty_live );
check( '  trimmed to 20: the next revise sends those 20, nothing said', array( $calls, $warned ), array( array( array( 'ReviseFixedPriceItem', sites( range( 4201, 4220 ) ) ) ), array() ) );

// A photo held back from a revise of 30 photos: the revise is sent none and
// says only that; the photos past the limit are said when some are sent.
hosting( true );
$held_many = photographed( 730, range( 4401, 4430 ) );
kept_on_ebay( 4401 );
live( 730 );
$API->refuse['coin-4402.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned ) = pushed( $held_many );
check( 'a photo held back from a revise of 30: no PictureDetails, the held warning only', array( $calls, $warned ), array( array( array( 'ReviseFixedPriceItem', array() ) ), array( unchanged_warning( 730, array( 4402 => 'eBay refused the file' ) ) ) ) );

// That listing ended (the review's C5): the new listing goes with the first
// 24 that can be put on eBay, and says both what it left out and the 5 it
// had no room for, once each.
update_post_meta( 730, '_ebay_listing_status', 'Ended' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '1100000730', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'Site' => 'US' ) );
list( $calls, $uploads, $warned, $result ) = pushed( $held_many );
check( '  ended: the revise sent none, the new listing the kept photo and 23 more', array( is_array( $result ) ? $result['action'] : $result->get_error_message(), $calls ), array( 'relisted', array( array( 'ReviseFixedPriceItem', array() ), array( 'GetItem', array() ), array( 'AddFixedPriceItem', array_merge( array( kept( 4401 ) ), epss( range( 4403, 4425 ) ) ) ) ) ) );
check( '  the left-out warning and the 5 past the limit, once each', $warned, array( left_out_warning( 730, array( 4402 => 'eBay refused the file' ) ), past_cap_warning( 730, 5 ) ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'], $API->refuse['coin-4402.jpg'] );
hosting( false );

// eBay also limits how long a listing's photo addresses are together: about
// 3975 characters (PictureDetailsType; a seller reports 4000). Twelve never
// reached it; 24 long website addresses can - an offloader's S3 bucket, with
// the names imported photos are given (the review's k11_long_urls_24). The
// list stops before the one that would pass 3900, and the rest are not sent.
$long_prefix = 'https://numismax-collectibles-media.s3.us-east-2.amazonaws.com/wp-content/uploads/2026/10/';
function long_photos( WC_Product $product, array $ids, $prefix ) {
	foreach ( $ids as $id ) {
		$GLOBALS['W']['images'][ $id ] = $prefix . '1881-s-morgan-silver-dollar-pcgs-ms65-' . $product->get_id() . '-ebay-kp4aaosw1zl3x9a' . $id . 'q-1600.jpg';
	}
}
function chars( array $urls ) { return array_sum( array_map( 'strlen', $urls ) ); }
$long = photographed( 736, range( 6001, 6024 ) );
long_photos( $long, range( 6001, 6024 ), $long_prefix );
live( 736 );
list( $calls, $uploads, $warned ) = pushed( $long );
check( 'twenty-four long website addresses (3984 characters): the revise sends the first 23, 3818 characters', array( chars( sites_of( range( 6001, 6024 ) ) ), count( $calls[0][1] ), chars( $calls[0][1] ), $calls[0][1] === sites_of( range( 6001, 6023 ) ) ), array( 3984, 23, 3818, true ) );
check( '  one warning: the addresses too long together, the one after the first 23 not sent', $warned, array( too_long_warning( 736, 1, 23 ) ) );
$long30 = photographed( 737, range( 6101, 6130 ) );
long_photos( $long30, range( 6101, 6130 ), $long_prefix );
list( $calls, $uploads, $warned ) = pushed( $long30 );
check( '  thirty of them: the first 23 go; the 7 after them, the 6 past 24 among them, said in that warning', array( count( $calls[0][1] ), $warned ), array( 23, array( too_long_warning( 737, 7, 23 ) ) ) );
list( $pics, $uploads, $warned ) = built( $long30 );
check( '  Verify builds the same: the first 23, the same warning', array( count( $pics ), $warned ), array( 23, array( too_long_warning( 737, 7, 23 ) ) ) );
// The list stops: a short address after the one that would pass is not sent.
$stops = photographed( 738, range( 6201, 6224 ) );
long_photos( $stops, range( 6201, 6222 ), $long_prefix );
$GLOBALS['W']['images'][6223] = 'https://shop.example/' . str_repeat( 'x', 400 ) . '.jpg';
list( $pics, $uploads, $warned ) = built( $stops );
check( '  22 long, a 23rd too long to fit, then a short 24th: the first 22 go, not the short one, and the 2 are said', array( $pics === sites_of( range( 6201, 6222 ) ), $warned ), array( true, array( too_long_warning( 738, 2, 22 ) ) ) );
// Up to the limit itself, all go.
$exact = photographed( 739, range( 6301, 6320 ) );
foreach ( range( 6301, 6320 ) as $id ) {
	$GLOBALS['W']['images'][ $id ] = 'https://shop.example/' . str_pad( (string) $id, 170, 'y' ) . '.jpg';
}
list( $pics, $uploads, $warned ) = built( $exact );
check( '  twenty addresses of exactly 3900 characters together: all 20 go, nothing said', array( chars( $pics ), count( $pics ), $warned ), array( 3900, 20, array() ) );
$GLOBALS['W']['images'][6320] .= 'z';
list( $pics, $uploads, $warned ) = built( $exact );
check( '  one character more: the first 19, the one after them said', array( count( $pics ), $warned ), array( 19, array( too_long_warning( 739, 1, 19 ) ) ) );
$GLOBALS['W']['images'][6320] = 'https://shop.example/' . str_pad( '6320 ', 170, 'y' ) . '.jpg';
list( $pics, $uploads, $warned ) = built( $exact );
check( '  3900 as written, but a space goes as %20: counted as sent, 3902, so the first 19', array( chars( sites_of( range( 6301, 6320 ) ) ), count( $pics ), $warned ), array( 3900, 19, array( too_long_warning( 739, 1, 19 ) ) ) );
// eBay's own addresses are short: 24 of them never reach it.
hosting( true );
$short = photographed( 740, range( 6401, 6430 ) );
list( $pics, $uploads, $warned ) = built( $short );
check( 'thirty, the setting on: 24 eBay addresses, well within it, and the count\'s own warning', array( count( $pics ), chars( $pics ) < 3900, $warned ), array( 24, true, array( past_cap_warning( 740, 6 ) ) ) );
hosting( false );

// A variable product: its photos are the listing's PictureDetails, up to 24.
// eBay allows 12 a variation (VariationSpecificPictureSet), but a push sends
// a variation none of its own, so no variation can be sent more than 12.
class Picture_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
}
class Picture_Variation extends WC_Product_Variation {
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ); }
}
photographed( 731, range( 4501, 4515 ) );
$variable = new Picture_Variable( 731, array( 'name' => 'Sleeves 731', 'description' => 'Box of 100.', 'sku' => 'SLV-731', 'type' => 'variable', 'image_id' => 4501, 'gallery' => range( 4502, 4515 ) ) );
$variable->variation_attributes = array( 'size' => array( 'Small', 'Large' ) );
photographed( 7311, range( 4516, 4530 ) );
photographed( 7312, array( 4531 ) );
$variable->children = array( 7311, 7312 );
new Picture_Variation( 7311, array( 'sku' => 'SLV-731-S', 'size' => 'Small', 'image_id' => 4516, 'gallery' => range( 4517, 4530 ), 'stock' => 3 ) );
new Picture_Variation( 7312, array( 'sku' => 'SLV-731-L', 'size' => 'Large', 'image_id' => 4531, 'stock' => 2 ) );
list( $pics, $uploads, $warned ) = built( $variable );
$var_xml = (string) end( $GLOBALS['ITEMS'] )[1];
check( 'a variable product with 15 photos: all 15 in the listing\'s PictureDetails, nothing said', array( $pics, $warned ), array( sites( range( 4501, 4515 ) ), array() ) );
check( '  its two variations are sent, with no pictures of their own (no VariationSpecificPictureSet, nothing past 12 a variation)', array( substr_count( $var_xml, '<Variation>' ), false !== strpos( $var_xml, '<Pictures>' ), false !== strpos( $var_xml, 'VariationSpecificPictureSet' ), false !== strpos( $var_xml, 'coin-4516' ) ), array( 2, false, false, false ) );
$var_src = substr( $exp_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' ), (int) strpos( $exp_src, "\$xml .= '<Variations>'" ), (int) strpos( $exp_src, "\$xml .= \"</Variations>\\n\";" ) - (int) strpos( $exp_src, "\$xml .= '<Variations>'" ) );
check( '  the Variations block\'s source sends no picture at all', array( strlen( $var_src ) > 500, false !== stripos( $var_src, 'picture' ), false !== stripos( $var_src, 'image' ) ), array( true, false, false ) );

// The limit is one constant, 24, used for every list of photos a listing is
// sent; the description's own image placeholders were 24 already.
$exporter_ref = new ReflectionClass( 'TCGiant_Sync_Exporter' );
check( 'MAX_IMAGES is 24, and the description templates\' {{image.N}} go to 24 too', array( $exporter_ref->getConstant( 'MAX_IMAGES' ), TCGiant_Sync_Templates::MAX_IMAGE_N ), array( 24, 24 ) );

// Every other place: one builder of PictureDetails, and the calls that use it.
$builders = array();
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ), glob( dirname( __DIR__ ) . '/admin/views/*.php' ) ) as $file ) {
	$n = substr_count( (string) file_get_contents( $file ), "'<PictureDetails>'" ) + substr_count( (string) file_get_contents( $file ), '<PictureURL>' );
	if ( $n ) {
		$builders[ basename( $file ) ] = $n;
	}
}
check( 'one place builds PictureDetails: build_item_xml(), from get_product_image_urls()', $builders, array( 'class-tcgiant-sync-exporter.php' => 2 ) );
$exp_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
check( '  get_product_image_urls() is called once, there', substr_count( $exp_src, '$this->get_product_image_urls(' ), 1 );
$adm_src = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
$tpl_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php' );
check( '  Verify and the preview\'s Check with eBay build through it', array( false !== strpos( $adm_src, '$exporter->build_item_xml_public( $product, $settings )' ), false !== strpos( $tpl_src, '$exporter->build_item_xml_public( $product, $settings )' ) ), array( true, true ) );
$relist_src = substr( $api_src, (int) strpos( $api_src, 'public function relist_fixed_price_item(' ), (int) strpos( $api_src, 'public function get_orders(' ) - (int) strpos( $api_src, 'public function relist_fixed_price_item(' ) );
check( '  RelistItem and RelistFixedPriceItem send no pictures: eBay keeps the listing\'s own', array( false !== strpos( $relist_src, "'RelistItem'" ), false !== strpos( $relist_src, "'RelistFixedPriceItem'" ), false === stripos( $relist_src, 'Picture' ) ), array( true, true, true ) );

// Every Revise of an item: one place, do_push(), whose item is built for it.
$revise_sites = array();
foreach ( array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ), glob( dirname( __DIR__ ) . '/admin/views/*.php' ), array( dirname( __DIR__ ) . '/tcgiant-sync.php', dirname( __DIR__ ) . '/uninstall.php' ) ) as $file ) {
	$src = (string) file_get_contents( $file );
	$n   = substr_count( $src, '->revise_item(' ) + substr_count( $src, '->revise_fixed_price_item(' );
	if ( $n ) {
		$revise_sites[ basename( $file ) ] = $n;
	}
}
// 3.28.0: two of each in do_push() - the listing the push began with, and a
// live relist of it the push follows - each built for a revise.
check( 'every ReviseItem and ReviseFixedPriceItem of an item is do_push()\'s, two of each', array( $revise_sites, substr_count( $exp_src, '? $api->revise_fixed_price_item( $full_xml )' ), substr_count( $exp_src, ': $api->revise_item( $full_xml );' ) ), array( array( 'class-tcgiant-sync-exporter.php' => 4 ), 2, 2 ) );
// 3.28.1: with the settings a revise sends, which keep a live listing's category.
check( '  and the item each sends is built for a revise', array( substr_count( $exp_src, '$item_xml = $this->build_item_xml( $product, $sent_settings, ! empty( $ebay_item_id ) );' ), substr_count( $exp_src, '$item_xml = $this->build_item_xml( $product, $relist_live ? $kept_settings : $settings, $relist_live );' ) ), array( 1, 1 ) );

// No empty PictureDetails anywhere, which eBay would read as "no photos":
// every item this run built or sent has none, or one with its photos in it.
$nodes = array();
$empty = 0;
foreach ( $GLOBALS['ITEMS'] as $item ) {
	list( $call, $xml ) = $item;
	$n                  = picture_nodes( $xml );
	$nodes[ $call ][ $n ] = ( $nodes[ $call ][ $n ] ?? 0 ) + 1;
	preg_match_all( '#<PictureDetails\b[^>]*>(.*?)</PictureDetails>#s', $xml, $blocks );
	foreach ( $blocks[1] as $block ) {
		if ( false === stripos( $block, '<PictureURL>http' ) ) {
			$empty++;
		}
	}
	$empty += preg_match_all( '#<PictureDetails\b[^>]*/>#', $xml ) + ( $n - count( $blocks[1] ) );
}
ksort( $nodes );
check( 'no empty PictureDetails node in any item built or sent: every PictureDetails has its photos', $empty, 0 );
check( '  each new listing and Verify item has one; a revise none only where photos were held back (10 here)', array( $nodes['AddFixedPriceItem'] ?? array(), $nodes['AddItem'] ?? array(), $nodes['ReviseFixedPriceItem'][0] ?? 0, $nodes['ReviseItem'][0] ?? 0, isset( $nodes['Verify'][0] ), $nodes['GetItem'] ?? array() ), array( array( 1 => count( array_filter( $GLOBALS['ITEMS'], function ( $i ) { return 'AddFixedPriceItem' === $i[0]; } ) ) ), array( 1 => count( array_filter( $GLOBALS['ITEMS'], function ( $i ) { return 'AddItem' === $i[0]; } ) ) ), 9, 1, false, array( 0 => 8 ) ) );
check( '  the shipped code never writes one: <PictureDetails> only when there are photos, and no self-closed one', array( substr_count( $exp_src, "if ( ! empty( \$images ) ) {\n\t\t\t\$picture_xml = '<PictureDetails>' . \"\\n\";" ), preg_match( '#<PictureDetails\s*/>#', implode( '', array_map( 'file_get_contents', array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ), glob( dirname( __DIR__ ) . '/admin/*.php' ) ) ) ) ) ), array( 1, 0 ) );

done();
