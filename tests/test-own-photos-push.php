<?php
/**
 * Harness: what a push leaves for the image batch, and a new listing that
 * would go without its main photo (3.30.4).
 *
 * A coin shop's new listings - its ended Good 'Til Cancelled listings pushed
 * again as auctions - went up with a gallery photo first: the never-mix rule
 * (3.27.8) leaves out of a listing whose other photos are on eBay any photo
 * that cannot be put on eBay - its upload refused past the shops' shared call
 * ceiling, its file offloaded - and that was the main photo. eBay shows a
 * listing's first picture as its main one, and with "Overwrite Images" on the
 * sync then made that photo the product's main photo too.
 *
 * Now a new listing, and Verify, which checks one, is refused before eBay
 * when the product's main photo is not the first photo decided, saying why;
 * a revise keeps the listing's own photos, as before, and gallery photos left
 * out still go as before, with their warning.
 *
 * And a push keeps, for the image batch (test-own-photos.php): on each photo
 * it uploads, the copy's eBay number (_tcgiant_ebay_number, beside any
 * earlier one), also for one cached before 3.30.4; and on the product, once
 * eBay has taken the item, what it sent (_tcgiant_pushed_photos) - not for a
 * revise that sent no photos.
 *
 * Loaded for real: the exporter's build and push (do_push(), and
 * process_single_push() for what is recorded once eBay answers), the API
 * class with its calls and its picture upload played, the templates.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_LISTED_KEY  = '_ebay_auction_listed_item';
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	public static function listing_already_settled( $product_id, $item_id ) { return false; }
	public static function apply_ended_listing_stock( $product_id, $listed, $sold, $item = null ) { return (int) wc_get_product( $product_id )->get_stock_quantity(); }
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
}
class TCGiant_Sync_Listing_Link {
	public static function find_products_sharing_item_id( $product_id, $item_id ) { return array(); }
}
function get_post_status( $id ) { return 'publish'; }
function get_attached_file( $id ) { return $GLOBALS['W']['files'][ (int) $id ] ?? false; }
function get_the_title( $id ) { return 'Photo ' . (int) $id; }

/** eBay, its picture upload played: as test-picture-hosting.php's. */
class Picture_API extends Template_World_API {
	public $uploads     = array();
	public $refuse      = array();
	public $budget_left = null;
	public $round       = 'a';
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
		return $this->refuse[ basename( $file_path ) ] ?? eps( (int) preg_replace( '/\D/', '', basename( $file_path ) ), $this->round );
	}
}
$GLOBALS['API'] = new Picture_API();
$API            = $GLOBALS['API'];
$tcg_slot       = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_slot->setAccessible( true );
}
$tcg_slot->setValue( null, $API );
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';

$DIR = sys_get_temp_dir() . '/tcg-own-photos-push-' . getmypid();
if ( ! is_dir( $DIR ) ) {
	mkdir( $DIR, 0777, true );
}
register_shutdown_function( function () use ( $DIR ) {
	foreach ( (array) glob( $DIR . '/*' ) as $file ) {
		@unlink( $file );
	}
	@rmdir( $DIR );
} );

const NUMBER_META = '_tcgiant_ebay_number';
const PUSHED_META = '_tcgiant_pushed_photos';

/** A photo's address on the website, and what UploadSiteHostedPictures answers for it. */
function site( $id ) { return 'https://shop.example/wp-content/uploads/2026/10/coin-' . $id . '.jpg'; }
function eps( $id, $round = 'a' ) { return 'https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/' . $round . 'coin' . $id . '/$_1.JPG?set_id=8800005007'; }
function number_of( $id, $round = 'a' ) { return $round . 'coin' . $id; }
function epss( array $ids, $round = 'a' ) { return array_map( function ( $id ) use ( $round ) { return eps( $id, $round ); }, $ids ); }
function sites( array $ids ) { return array_map( 'site', $ids ); }
function kept( $id ) { return 'https://i.ebayimg.com/images/g/kept' . $id . 'x/s-l1600.jpg'; }
function kept_on_ebay( $id ) {
	$GLOBALS['W']['images'][ $id ] = kept( $id );
	unset( $GLOBALS['W']['files'][ $id ] );
}
function hosting( $on ) { update_option( 'tcgiant_sync_ebay_settings', array( 'host_images_on_ebay' => $on ? '1' : '0' ) ); }

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
		'name'        => '1921 Morgan Dollar ' . $product_id,
		'description' => 'A Morgan dollar.',
		'sku'         => 'MD-' . $product_id,
		'image_id'    => $photo_ids[0],
		'gallery'     => array_slice( $photo_ids, 1 ),
		'stock'       => 1,
	), $data ) );
}

function pictures( $xml ) {
	preg_match_all( '#<PictureURL>(.*?)</PictureURL>#', (string) $xml, $m );
	return array_map( 'html_entity_decode', $m[1] );
}
function warnings_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'warning' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}
function errors_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'error' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}

/** A push (do_push()): each eBay call with its photos, the uploads, the warnings, the result. */
function pushed( WC_Product $product ) {
	$GLOBALS['API']->calls   = array();
	$GLOBALS['API']->uploads = array();
	$from                    = count( $GLOBALS['W']['log'] );
	$result                  = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	$calls                   = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$calls[] = array( $call[0], pictures( $call[1] ) );
	}
	return array( $calls, $GLOBALS['API']->uploads, warnings_from( $from ), $result );
}

/** The whole push, as the queue runs it (process_single_push()): the calls, and what is logged. */
function push_whole( $product_id ) {
	$GLOBALS['API']->calls   = array();
	$GLOBALS['API']->uploads = array();
	$from                    = count( $GLOBALS['W']['log'] );
	exporter()->process_single_push( $product_id );
	$calls = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		$calls[] = array( $call[0], pictures( $call[1] ) );
	}
	return array( $calls, warnings_from( $from ), errors_from( $from ) );
}

/** Verify's build: its photos and the refusal it gives. */
function verified( WC_Product $product ) {
	$GLOBALS['API']->uploads = array();
	$xml                     = item_xml( $product, push_settings( $product ) );
	$refused                 = exporter()->description_too_long();
	return array( pictures( $xml ), is_wp_error( $refused ) ? $refused->get_error_code() : '' );
}

function main_refusal( $photo, $why, $then = 'Push it again once the photo can be put on eBay, or choose another main photo.' ) {
	return sprintf( 'Cannot list on eBay: this product\'s main photo (#%d) could not be put on eBay (%s), and a new listing without it would show another photo first. Nothing was sent to eBay. %s', $photo, $why, $then );
}
function message_of( $result ) { return is_wp_error( $result ) ? $result->get_error_message() : ( is_array( $result ) ? 'made: ' . $result['action'] : '' ); }

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA NEW LISTING NEVER GOES WITHOUT ITS MAIN PHOTO\n" . str_repeat( '=', 124 ) . "\n";

hosting( true );

// The coin shop's case: a gallery photo kept on eBay at import, the main
// photo the shop's own, its upload refused past the shared call ceiling.
$add = photographed( 801, array( 81, 82, 83 ), array( 'stock' => 1 ) );
kept_on_ebay( 82 );
update_post_meta( 801, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 801, '_ebay_export_listing_duration', 'Days_10' );
$API->budget_left = 0;
list( $calls, $uploads, $warned, $result ) = pushed( $add );
check( 'a new listing whose main photo is refused past the call ceiling: no AddItem sent', $calls, array() );
check( '  the push refused, saying why and what to do', message_of( $result ), main_refusal( 81, 'Daily API call budget exhausted' ) );
check( '  its code', is_wp_error( $result ) ? $result->get_error_code() : '', 'main_photo_left_out' );
check( '  and no left-out warning for a listing that was not made', $warned, array() );
$API->budget_left = null;
list( $calls, $uploads, $warned, $result ) = pushed( $add );
check( 'pushed again once the photo can go: AddItem, the main photo first', array( $calls, message_of( $result ) ), array( array( array( 'AddItem', array( eps( 81 ), kept( 82 ), eps( 83 ) ) ) ), 'made: created' ) );

// AddFixedPriceItem, the main photo's file offloaded.
$off = photographed( 802, array( 84, 85 ) );
kept_on_ebay( 85 );
unlink( $GLOBALS['W']['files'][84] );
list( $calls, , $warned, $result ) = pushed( $off );
check( 'AddFixedPriceItem, the main photo\'s file not on this server: not sent, refused', array( $calls, message_of( $result ) ), array( array(), main_refusal( 84, 'its file is not on this server', 'Push it again once the photo\'s file is back on this server, or choose another main photo.' ) ) );

// The main photo deleted: no address, no file, no copy on eBay.
$gone = photographed( 803, array( 86, 87 ) );
unset( $GLOBALS['W']['images'][86], $GLOBALS['W']['files'][86] );
list( $calls, , $warned, $result ) = pushed( $gone );
check( 'the main photo deleted (no address, no copy on eBay): not sent, refused', array( $calls, message_of( $result ) ), array( array(), main_refusal( 86, 'it has no address on your website and no copy on eBay' ) ) );
hosting( false );
list( $calls, , $warned, $result ) = pushed( $gone );
check( '  the setting off, the same', array( $calls, message_of( $result ) ), array( array(), main_refusal( 86, 'it has no address on your website and no copy on eBay' ) ) );
hosting( true );

// A product whose one photo is its main photo, deleted: never sent with no photo at all.
$solo = photographed( 811, array( 105 ) );
unset( $GLOBALS['W']['images'][105], $GLOBALS['W']['files'][105] );
list( $calls, , , $result ) = pushed( $solo );
check( 'its only photo the main photo, deleted: not sent with no photo at all, refused', array( $calls, message_of( $result ) ), array( array(), main_refusal( 105, 'it has no address on your website and no copy on eBay' ) ) );

// A staging copy uploads nothing: its refusal, as test-picture-hosting.php plays it.
$stage  = photographed( 804, array( 88, 89 ) );
kept_on_ebay( 89 );
$staged = new WP_Error( 'staging_blocked', TCGiant_Sync_Environment::message( 'wp-config' ) );
$API->refuse['coin-88.jpg'] = $staged;
list( $calls, , , $result ) = pushed( $stage );
check( 'a staging copy, the main photo\'s upload refused there: not sent, refused for it', array( $calls, message_of( $result ) ), array( array(), main_refusal( 88, rtrim( TCGiant_Sync_Environment::message( 'wp-config' ), '. ' ) ) ) );
unset( $API->refuse['coin-88.jpg'] );

// A gallery photo left out: the listing goes without it, as before.
$gal = photographed( 805, array( 91, 92, 93 ) );
kept_on_ebay( 91 );
$API->refuse['coin-93.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, , $warned, $result ) = pushed( $gal );
check( 'a gallery photo left out: AddFixedPriceItem goes without it, with its warning, as before', array( $calls, count( $warned ), message_of( $result ) ), array( array( array( 'AddFixedPriceItem', array( kept( 91 ), eps( 92 ) ) ) ), 1, 'made: created' ) );
unset( $API->refuse['coin-93.jpg'] );

// A product with no main photo of its own sends its gallery as before.
$none = photographed( 806, array( 94, 95 ), array( 'image_id' => 0, 'gallery' => array( 94, 95 ) ) );
list( $calls, , , $result ) = pushed( $none );
check( 'no main photo at all: its gallery goes as before', array( $calls, message_of( $result ) ), array( array( array( 'AddFixedPriceItem', epss( array( 94, 95 ) ) ) ), 'made: created' ) );

// A revise keeps the listing's own photos when the main photo cannot go, as before.
$rev = photographed( 807, array( 96, 97 ) );
kept_on_ebay( 97 );
update_post_meta( 807, '_ebay_item_id', '110000000807' );
update_post_meta( 807, '_ebay_listing_status', 'Active' );
$API->budget_left = 0;
list( $calls, , $warned, $result ) = pushed( $rev );
check( 'a revise whose main photo cannot go: sent with no PictureDetails, as before (3.27.8)', array( $calls, message_of( $result ), count( $warned ) ), array( array( array( 'ReviseFixedPriceItem', array() ) ), 'made: updated', 1 ) );

// An ended listing pushed again: the revise is refused as ended, eBay is
// read, and the new listing in its place would go without its main photo.
$ended = photographed( 808, array( 98, 99 ), array( 'stock' => 1 ) );
kept_on_ebay( 99 );
update_post_meta( 808, '_ebay_item_id', '110000000808' );
update_post_meta( 808, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 808, '_ebay_listing_status', 'Ended' );
update_post_meta( 808, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 808, '_ebay_export_listing_duration', 'Days_10' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '110000000808', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
list( $calls, , $warned, $result ) = pushed( $ended );
check( 'an ended GTC pushed again as an auction, its main photo refused: the revise and the read, and no AddItem', array( array_column( $calls, 0 ), message_of( $result ) ), array( array( 'ReviseFixedPriceItem', 'GetItem' ), main_refusal( 98, 'Daily API call budget exhausted' ) ) );
check( '  no left-out warning, and the product still follows its ended listing', array( $warned, get_post_meta( 808, '_ebay_item_id', true ) ), array( array(), '110000000808' ) );
$API->budget_left = null;
list( $calls, , , $result ) = pushed( $ended );
check( '  pushed again once the photo can go: the new listing, its main photo first', array( array_column( $calls, 0 ), $calls[2][1] ?? null, message_of( $result ) ), array( array( 'ReviseFixedPriceItem', 'GetItem', 'AddItem' ), array( eps( 98 ), kept( 99 ) ), 'made: relisted' ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );

// Verify, which checks a new listing, says the same.
$API->budget_left = 0;
$ver = photographed( 809, array( 101, 102 ) );
kept_on_ebay( 102 );
list( $pics, $code ) = verified( $ver );
check( 'Verify of a product whose main photo cannot go: refused before eBay with the same words', $code, 'main_photo_left_out' );
$API->budget_left = null;

// The whole push, as the queue runs it: the product says why, and the log.
$whole = photographed( 810, array( 103, 104 ) );
kept_on_ebay( 104 );
$API->budget_left = 0;
list( $calls, $warned, $errors ) = push_whole( 810 );
check( 'the whole push: nothing sent, the product marked refused with the reason, and the log saying it', array( $calls, get_post_meta( 810, '_ebay_export_status', true ), get_post_meta( 810, '_ebay_export_error', true ), count( $errors ) ), array( array(), 'error', main_refusal( 103, 'Daily API call budget exhausted' ), 1 ) );
check( '  the log line', $errors[0] ?? '', 'Export failed for "1921 Morgan Dollar 810" (WC #810): ' . main_refusal( 103, 'Daily API call budget exhausted' ) );
$API->budget_left = null;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT A PUSH KEEPS FOR THE IMAGE BATCH\n" . str_repeat( '=', 124 ) . "\n";

hosting( true );
$keep = photographed( 820, array( 111, 112, 113 ) );
list( $calls, $uploads ) = pushed( $keep );
check( 'each photo uploaded keeps its copy\'s eBay number', array( get_post_meta( 111, NUMBER_META, false ), get_post_meta( 113, NUMBER_META, false ) ), array( array( number_of( 111 ) ), array( number_of( 113 ) ) ) );
// One cached before 3.30.4: only its address.
$old = photographed( 821, array( 114, 115 ) );
foreach ( array( 114, 115 ) as $id ) {
	update_post_meta( $id, '_tcgiant_eps_url', eps( $id, 'old' ) );
	update_post_meta( $id, '_tcgiant_eps_key', (string) filemtime( $GLOBALS['W']['files'][ $id ] ) );
	update_post_meta( $id, '_tcgiant_eps_time', time() - DAY_IN_SECONDS );
}
list( $calls, $uploads ) = pushed( $old );
check( 'a copy cached before 3.30.4, used from the cache: its number kept on the photo, nothing uploaded', array( $uploads, get_post_meta( 114, NUMBER_META, false ) ), array( array(), array( number_of( 114, 'old' ) ) ) );

// What a push sent, recorded once eBay took it.
list( $calls ) = push_whole( 820 );
$rec = get_post_meta( 820, PUSHED_META, true );
check( 'a push eBay took records what it sent: the listing, the photos in order, their addresses, the photos shown', array( $rec['item'] ?? null, $rec['ids'] ?? null, $rec['urls'] ?? null, $rec['shown'] ?? null ), array( '110000000001', array( 111, 112, 113 ), epss( array( 111, 112, 113 ) ), array( 111, 112, 113 ) ) );
hosting( false );
$site_push = photographed( 822, array( 116, 117 ) );
push_whole( 822 );
$rec = get_post_meta( 822, PUSHED_META, true );
check( '  from the website: the addresses it sent', array( $rec['ids'] ?? null, $rec['urls'] ?? null ), array( array( 116, 117 ), sites( array( 116, 117 ) ) ) );
// A revise that sent no photos leaves the record as it was.
hosting( true );
$held = photographed( 823, array( 118, 119 ) );
kept_on_ebay( 119 );
update_post_meta( 823, '_ebay_item_id', '110000000823' );
update_post_meta( 823, '_ebay_listing_status', 'Active' );
update_post_meta( 823, PUSHED_META, array( 'item' => '110000000823', 'ids' => array( 118, 119 ), 'urls' => array( 'x', 'y' ), 'shown' => array( 118, 119 ), 'time' => 1 ) );
$API->budget_left = 0;
push_whole( 823 );
check( 'a revise that sent no photos (one held back): the record is the last push\'s', get_post_meta( 823, PUSHED_META, true )['urls'] ?? null, array( 'x', 'y' ) );
$API->budget_left = null;
// Past the cap: only what was sent.
$many = photographed( 824, range( 1201, 1230 ) );
push_whole( 824 );
$rec = get_post_meta( 824, PUSHED_META, true );
check( 'thirty photos: the record holds the 24 sent, and the 30 shown', array( count( $rec['ids'] ?? array() ), count( $rec['urls'] ?? array() ), count( $rec['shown'] ?? array() ), $rec['ids'][23] ?? null ), array( 24, 24, 30, 1224 ) );

// A new upload after the 20-day cache: the new number kept beside the old.
$API->round = 'b';
foreach ( array( 111, 112, 113 ) as $id ) {
	update_post_meta( $id, '_tcgiant_eps_time', time() - 21 * DAY_IN_SECONDS );
}
pushed( $keep );
check( 'uploaded again past the 20-day cache: the new number kept on the photo', in_array( number_of( 111, 'b' ), (array) get_post_meta( 111, NUMBER_META, false ), true ), true );
$API->round = 'a';

// A copy cached before 3.30.4, past its 20 days before the pass has copied
// its number over: the number is kept on the photo before it is uploaded
// again - here, whatever becomes of the upload.
$stale = photographed( 825, array( 120, 121 ) );
update_post_meta( 120, '_tcgiant_eps_url', eps( 120, 'old' ) );
update_post_meta( 120, '_tcgiant_eps_key', (string) filemtime( $GLOBALS['W']['files'][120] ) );
update_post_meta( 120, '_tcgiant_eps_time', time() - 21 * DAY_IN_SECONDS );
$API->budget_left = 0;
pushed( $stale );
check( 'a copy cached before 3.30.4 and past its time: its number kept on the photo before the upload is asked for again', get_post_meta( 120, NUMBER_META, false ), array( number_of( 120, 'old' ) ) );
$API->budget_left = null;

done();
