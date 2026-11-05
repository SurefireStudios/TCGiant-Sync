<?php
/**
 * Harness: a relist sends the product's photos when they changed here since
 * the listing had them, and the next import knows them (3.32.1).
 *
 * A coin shop reuses a product when a coin sells: it swaps in photos of the
 * new coin and a new price, and the listing goes back on eBay. The sold
 * coin's photos stay in the Media Library, still attached to the product.
 * Until 3.32.1 a relist - Listings, Bulk Relist, Auto-Relist - sent eBay no
 * photos, so the listing went back up with the sold coin's pictures; the
 * next import knew those pictures as the old photos, still there, and the
 * product showed the sold coin again: in place of the new photos with
 * "Overwrite Images" on, behind them with it off. (3.31.1 fixed the price
 * half of the same case.)
 *
 * Now a relist compares the ended listing's pictures, in order, with the
 * product's photos (the featured image, then the gallery, up to 24): each
 * picture must be, in its place, a number its photo is on eBay as, or the
 * number of the address the photo was downloaded from, and as many. The same:
 * nothing more is sent, byte for byte as before. Different: the product's
 * photos go, decided exactly as a push decides them, and once eBay has taken
 * the relist they are recorded as a push records them
 * (TCGiant_Sync_Own_Photos::record_push()). When it cannot be told - no
 * pictures in eBay's answer, no photos on the product, a picture with no
 * number - none are sent, as before. Where a push would leave a photo out
 * because the others are on eBay, or the main photo cannot go, the relist is
 * refused before eBay, in plain words: Update eBay Listing lists it.
 *
 * The loop that must not come back (3.30.4's duplicate downloads): the
 * relist here, through the real exporter and API class in template-world,
 * with eBay's calls and picture upload played; the shop before it and the
 * import after it through the real image batch and TCGiant_Sync_Own_Photos
 * against SQLite, in test-own-photos.php's world (its --child=relist-shop and
 * --child=relist-import), with what the relist wrote carried over row for
 * row. eBay is played by what the relist sent: its own copies of pictures it
 * was sent from the website, under numbers of its own, in either of
 * GetItem's two shapes (learn_by_place()); the uploads' own pictures; or,
 * when it was sent none, the sold coin's.
 *
 * Also: Image Cleanup's wording for a reused product, and the "API budget:
 * N/M" log lines, whose M is now the limit N is counted against.
 *
 * @package TCGiant_Sync
 */

$CHILD = null;
foreach ( (array) ( $argv ?? array() ) as $tcg_arg ) {
	if ( 0 === strpos( $tcg_arg, '--child=' ) ) {
		$CHILD = substr( $tcg_arg, 8 );
	}
}
if ( 'staging' === $CHILD ) {
	define( 'TCGIANT_SYNC_IS_STAGING', true );
}

require __DIR__ . '/support/template-world.php';
require_once dirname( __DIR__ ) . '/includes/class-tcgiant-sync-variations.php';

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
/** When a photo was made (fix round 1: copies_shown() reads it), as the harness sets it. */
function get_post_field( $field, $id ) { return in_array( $field, array( 'post_date_gmt', 'post_date' ), true ) ? (string) ( $GLOBALS['W']['made'][ (int) $id ] ?? '' ) : ''; }
function wc_tax_enabled() { return false; }
function wc_prices_include_tax() { return false; }

/** A variable product with its variations, and a variation by its size. */
class Photo_Variable extends WC_Product_Variable {
	public $children = array();
	public function get_children() { return $this->children; }
}
class Photo_Variation extends WC_Product_Variation {
	public function get_variation_attributes() { return array( 'attribute_size' => $this->data['size'] ); }
}

/**
 * eBay, its picture upload played: each upload answered with its copy's
 * address, or refused. A call named in $resent went out that many times
 * more, after attempts eBay may have had (request_with_retry()'s count,
 * TCGiant_Sync_API::resends(), fix round 2).
 */
class Photo_API extends Template_World_API {
	public $uploads = array();
	public $refuse  = array();
	public $resent  = array();
	public function upload_picture( $file_path, $name = '' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'file_missing', 'Image file could not be read.' );
		}
		$this->uploads[] = basename( $file_path );
		return $this->refuse[ basename( $file_path ) ] ?? eps( (int) preg_replace( '/\D/', '', basename( $file_path ) ) );
	}
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->resends += (int) ( $this->resent[ $call_name ] ?? 0 );
		return parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
	}
}
$GLOBALS['API'] = new Photo_API();
$API            = $GLOBALS['API'];
$tcg_slot       = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_slot->setAccessible( true );
}
$tcg_slot->setValue( null, $API );
TCGiant_Sync_OAuth::$settings = array_merge( TCGiant_Sync_OAuth::$settings, array( 'export_category_id' => '11116', 'export_condition_id' => '3000', 'export_listing_type' => 'FixedPriceItem', 'export_listing_duration' => 'GTC' ) );

$DIR = sys_get_temp_dir() . '/tcg-relist-photos-' . getmypid();
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

/** eBay's number for a picture, as eBay makes them, and its addresses as GetItem gives them. */
function num( $tag, $n ) { return sprintf( '%sAAOSw%05d', $tag, $n ); }
function z_url( $number ) { return 'https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/' . $number . '/$_57.JPG?set_id=8800005007'; }
function g_url( $number ) { return 'https://i.ebayimg.com/images/g/' . $number . '/s-l1600.jpg'; }
/** A photo's address on the website, and what UploadSiteHostedPictures answers for it. */
function site( $id ) { return 'https://shop.example/wp-content/uploads/2026/10/coin-' . $id . '.jpg'; }
function eps( $id ) { return 'https://i.ebayimg.com/00/s/MTYwMFgxMjAw/z/acoin' . $id . '/$_1.JPG?set_id=8800005007'; }
function epss( array $ids ) { return array_map( 'eps', $ids ); }
function sites( array $ids ) { return array_map( 'site', $ids ); }
function hosting( $on ) { update_option( 'tcgiant_sync_ebay_settings', array( 'host_images_on_ebay' => $on ? '1' : '0' ) ); }

/** A photo on the website, with its file here. */
function photo( $id, $url = null ) {
	$GLOBALS['W']['images'][ $id ] = null === $url ? site( $id ) : $url;
	$file                          = $GLOBALS['DIR'] . '/coin-' . $id . '.jpg';
	if ( ! file_exists( $file ) ) {
		file_put_contents( $file, 'JPEG ' . $id );
	}
	$GLOBALS['W']['files'][ $id ] = $file;
}

/** A coin the shop reuses, its listing ended: these photos, main photo first, and these meta. */
function coin( $id, array $photos, array $meta = array(), array $data = array(), $class = 'WC_Product' ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	foreach ( $photos as $p ) {
		photo( $p );
	}
	$product = new $class( $id, array_merge( array( 'name' => '1921 Morgan Dollar ' . $id, 'description' => 'A Morgan dollar.', 'regular_price' => '20.00', 'stock' => 1, 'sku' => 'MD-' . $id, 'image_id' => $photos[0] ?? 0, 'gallery' => array_slice( $photos, 1 ) ), $data ) );
	foreach ( array_merge( array( '_ebay_item_id' => '110000000' . $id, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Ended', '_ebay_origin' => 'push', '_ebay_export_status' => 'pushed' ), $meta ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}

/** An ended listing as GetItem answers it, with these pictures (null: no PictureDetails). */
function ended_answer( $item_id, $pictures, $type = 'FixedPriceItem', array $more = array() ) {
	$item = array( 'ItemID' => $item_id, 'ListingType' => $type, 'Quantity' => '1', 'StartPrice' => '20.0', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' );
	if ( null !== $pictures ) {
		$item['PictureDetails'] = array( 'PictureURL' => $pictures );
	}
	return array( 'Ack' => 'Success', 'Item' => array_merge( $item, $more ) );
}

/** A relist (relist_ended_listing()): its answer, each call with its UUID as <UUID/>, the uploads. */
function relist_now( $id, array $answer, $source = 'Bulk relist', $in_hand = null, $changes = '' ) {
	$GLOBALS['API']->calls              = array();
	$GLOBALS['API']->uploads            = array();
	$GLOBALS['API']->answers['GetItem'] = $answer;
	$res = exporter()->relist_ended_listing( $id, $source, $in_hand, $changes );
	unset( $GLOBALS['API']->answers['GetItem'] );
	return array( $res, array_map( function ( $call ) { return array( $call[0], preg_replace( '#<UUID>[0-9a-fA-F]+</UUID>#', '<UUID/>', (string) $call[1] ) ); }, $GLOBALS['API']->calls ), $GLOBALS['API']->uploads );
}

/** PictureDetails as a push's item carries them. */
function pics( array $urls ) {
	$xml = "<PictureDetails>\n";
	foreach ( $urls as $url ) {
		$xml .= "\t<PictureURL>" . $url . "</PictureURL>\n";
	}
	return $xml . '</PictureDetails>';
}
/** A relist's body as RelistFixedPriceItem sends it: before 3.32.1 with no pictures. */
function relist_body( $item_id, $quantity, $price = '', $pictures = '', $variations = '' ) {
	return "\n<Item>\n<ItemID>" . $item_id . "</ItemID>\n<UUID/>" . ( null !== $quantity ? "\n<Quantity>" . $quantity . '</Quantity>' : '' ) . ( '' !== $price ? "\n<StartPrice>" . $price . '</StartPrice>' : '' ) . ( '' !== $variations ? "\n" . $variations : '' ) . ( '' !== $pictures ? "\n" . $pictures : '' ) . "\n</Item>";
}
/** The pictures a body sends. */
function pictures_in( $xml ) {
	preg_match_all( '#<PictureURL>(.*?)</PictureURL>#', (string) $xml, $m );
	return array_map( 'html_entity_decode', $m[1] );
}
function code_of( $res ) { return is_wp_error( $res ) ? $res->get_error_code() : ( is_array( $res ) ? 'relisted' : '' ); }
function logged( $words, $level = null ) {
	foreach ( $GLOBALS['W']['log'] as $row ) {
		if ( false !== strpos( $row[1], $words ) && ( null === $level || $level === $row[0] ) ) {
			return true;
		}
	}
	return false;
}
function warnings_since( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'warning' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}
function numbers( $id ) { return TCGiant_Sync_Own_Photos::numbers_of( $id ); }
function record_of( $id ) {
	$r = get_post_meta( $id, PUSHED_META, true );
	return is_array( $r ) ? array( $r['item'] ?? null, $r['ids'] ?? null, $r['urls'] ?? null, $r['shown'] ?? null, $r['read'] ?? null ) : null;
}

// ─── the staging child: what a copy's relist did, as JSON ──────────────────────

if ( 'staging' === $CHILD ) {
	hosting( true );
	coin( 790, array( 7901, 7902 ) );
	// One photo's copy cached by the live shop the copy was cloned from.
	update_post_meta( 7902, '_tcgiant_eps_url', eps( 7902 ) );
	update_post_meta( 7902, '_tcgiant_eps_key', (string) filemtime( $GLOBALS['W']['files'][7902] ) );
	update_post_meta( 7902, '_tcgiant_eps_time', time() );
	$from = count( $GLOBALS['W']['log'] );
	list( $res, $calls, $uploads ) = relist_now( 790, ended_answer( '110000000790', array( g_url( num( 'sold', 1 ) ), g_url( num( 'sold', 2 ) ) ) ) );
	echo json_encode( array(
		'staging'  => TCGIANT_SYNC_IS_STAGING,
		'why'      => rtrim( TCGiant_Sync_Environment::message( 'wp-config' ), '. ' ),
		'uploads'  => $uploads,
		'pictures' => pictures_in( $calls[1][1] ?? '' ),
		'warnings' => warnings_since( $from ),
	) );
	$GLOBALS['harness_done'] = true;
	exit( 0 );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE COIN SHOP'S CASE: A REUSED PRODUCT'S NEW PHOTOS GO WITH ITS RELIST\n" . str_repeat( '=', 124 ) . "\n";

hosting( true );
$SOLD = array( g_url( num( 'sold', 1 ) ), g_url( num( 'sold', 2 ) ) );
// The sold coin's photos, imported from its listing, still attached to the
// product; the new coin's, uploaded by the shop, its main photo and gallery.
photo( 7001 );
photo( 7002 );
update_post_meta( 7001, '_tcgiant_source_url', $SOLD[0] );
update_post_meta( 7002, '_tcgiant_source_url', $SOLD[1] );
coin( 700, array( 7005, 7006 ) );
TCGiant_Sync_Ebay_Live::record( 700, ended_answer( '110000000700', $SOLD )['Item'] );
$GLOBALS['W']['log'] = array();
list( $res, $calls, $uploads ) = relist_now( 700, ended_answer( '110000000700', $SOLD ) );
check( 'the listing shows the sold coin, the product the new one: GetItem, then RelistFixedPriceItem with the product\'s photos after the quantity', array( code_of( $res ), array_column( $calls, 0 ), $calls[1][1] ?? null ), array( 'relisted', array( 'GetItem', 'RelistFixedPriceItem' ), relist_body( '110000000700', 1, '', pics( epss( array( 7005, 7006 ) ) ) ) ) );
check( '  uploaded with "Host listing images on eBay?" on, each once, as a push uploads them', $uploads, array( 'coin-7005.jpg', 'coin-7006.jpg' ) );
check( '  each upload\'s number kept on its photo', array( numbers( 7005 ), numbers( 7006 ) ), array( array( 'acoin7005' ), array( 'acoin7006' ) ) );
check( '  recorded as a push records what it sent: the new listing, the photos in order, their addresses, the photos shown, not yet read', record_of( 700 ), array( '110000000001', array( 7005, 7006 ), epss( array( 7005, 7006 ) ), array( 7005, 7006 ), 0 ) );
check( '  the product follows the new listing', get_post_meta( 700, '_ebay_item_id', true ), '110000000001' );
check( '  the log line says it sent them', logged( 'Bulk relist: WC #700 relisted on eBay as 110000000001 (was 110000000700), quantity 1, with the product\'s photos, changed here since the listing had them.', 'success' ), true );
$live = get_post_meta( 700, '_ebay_live', true );
check( '  eBay\'s record moves to the new listing without the sold coin\'s pictures, which it no longer shows', array( $live['item_id'] ?? null, isset( $live['pictures'] ) ), array( '110000000001', false ) );
check( '  the sold coin\'s photos are not touched', array( numbers( 7001 ), get_post_meta( 7001, '_tcgiant_source_url', true ) ), array( array(), $SOLD[0] ) );

// The new listing ends in turn, unsold, and is relisted: eBay shows the
// copies the relist uploaded, in the form GetItem gives them.
update_post_meta( 700, '_ebay_listing_status', 'Ended' );
list( $res, $calls, $uploads ) = relist_now( 700, ended_answer( '110000000001', array( z_url( 'acoin7005' ), z_url( 'acoin7006' ) ) ) );
check( 'relisted again with nothing changed since: no PictureDetails and no upload - the body byte for byte as before 3.32.1', array( code_of( $res ), $calls[1][1] ?? null, $uploads ), array( 'relisted', relist_body( '110000000001', 1 ), array() ) );
check( '  the record is the first relist\'s still', record_of( 700 )[0], '110000000001' );
check( '  and the log says nothing of photos', logged( 'WC #700 relisted on eBay as 110000000001 (was 110000000001), quantity 1.', 'success' ), true );

echo "\nTHE SAME PHOTOS, EACH IN ITS PLACE: NOTHING MORE IS SENT, NOTHING UPLOADED\n" . str_repeat( '=', 124 ) . "\n";

// Imported from the listing: each photo downloaded from the picture in its place.
coin( 710, array( 7101, 7102, 7103 ) );
foreach ( array( 7101 => 1, 7102 => 2, 7103 => 3 ) as $p => $n ) {
	update_post_meta( $p, '_tcgiant_source_url', g_url( num( 'im', $n ) ) );
}
list( $res, $calls, $uploads ) = relist_now( 710, ended_answer( '110000000710', array( z_url( num( 'im', 1 ) ), z_url( num( 'im', 2 ) ), z_url( num( 'im', 3 ) ) ) ) );
check( 'imported photos, each the download of the picture in its place (another size form, a query string): the body as before 3.32.1', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000710', 1 ), array() ) );

// Pushed with eBay hosting: each photo kept the number it was uploaded as.
coin( 711, array( 7111, 7112 ) );
update_post_meta( 7111, NUMBER_META, num( 'up', 1 ) );
update_post_meta( 7112, NUMBER_META, num( 'up', 2 ) );
list( $res, $calls, $uploads ) = relist_now( 711, ended_answer( '110000000711', array( g_url( num( 'up', 1 ) ), g_url( num( 'up', 2 ) ) ) ) );
check( 'pushed with eBay hosting, each photo known by its upload\'s number: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000711', 1 ), array() ) );

// Pushed from the website, eBay's copies learnt by place by the first read.
coin( 712, array( 7121, 7122 ), array( PUSHED_META => array( 'item' => '110000000712', 'ids' => array( 7121, 7122 ), 'urls' => sites( array( 7121, 7122 ) ), 'shown' => array( 7121, 7122 ), 'time' => time(), 'read' => time(), 'learnt' => 2 ) ) );
update_post_meta( 7121, NUMBER_META, 'place:' . num( 'cp', 1 ) );
update_post_meta( 7122, NUMBER_META, 'place:' . num( 'cp', 2 ) );
list( $res, $calls, $uploads ) = relist_now( 712, ended_answer( '110000000712', array( z_url( num( 'cp', 1 ) ), z_url( num( 'cp', 2 ) ) ) ) );
check( 'pushed from the website, eBay\'s copies learnt by place for this product: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000712', 1 ), array() ) );

// Pushed before 3.30.4: only the cached copy's address.
coin( 713, array( 7131 ) );
update_post_meta( 7131, '_tcgiant_eps_url', eps( 7131 ) );
list( $res, $calls, $uploads ) = relist_now( 713, ended_answer( '110000000713', z_url( 'acoin7131' ) ) );
check( 'pushed before 3.30.4, known by the copy it cached (one picture, as GetItem gives one: a plain address): as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000713', 1 ), array() ) );

// An eBay stand-in (Keep eBay-hosted URLs): the photo's own address is eBay's.
coin( 714, array( 7141 ) );
$GLOBALS['W']['images'][7141] = g_url( num( 'kp', 1 ) );
update_post_meta( 7141, '_tcgiant_external_url', g_url( num( 'kp', 1 ) ) );
update_post_meta( 7141, '_tcgiant_source_url', g_url( num( 'kp', 1 ) ) );
list( $res, $calls, $uploads ) = relist_now( 714, ended_answer( '110000000714', array( z_url( num( 'kp', 1 ) ) ) ) );
check( 'an eBay stand-in, its address the picture\'s: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000714', 1 ), array() ) );

// More photos than a listing takes: the first 24 are what a push sent.
$many = range( 7201, 7226 );
coin( 715, $many );
foreach ( $many as $n => $p ) {
	update_post_meta( $p, NUMBER_META, num( 'mn', $n + 1 ) );
}
list( $res, $calls, $uploads ) = relist_now( 715, ended_answer( '110000000715', array_map( function ( $n ) { return g_url( num( 'mn', $n ) ); }, range( 1, 24 ) ) ) );
check( '26 photos, the listing their first 24: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000715', 1 ), array() ) );

// No main photo of its own: its gallery is what a push sent.
coin( 716, array( 7111, 7112 ), array(), array( 'image_id' => 0, 'gallery' => array( 7111, 7112 ) ) );
update_post_meta( 7111, NUMBER_META, num( 'up', 1 ) );
update_post_meta( 7112, NUMBER_META, num( 'up', 2 ) );
list( $res, $calls, $uploads ) = relist_now( 716, ended_answer( '110000000716', array( g_url( num( 'up', 1 ) ), g_url( num( 'up', 2 ) ) ) ) );
check( 'no main photo, its gallery the listing\'s pictures: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000716', 1 ), array() ) );

echo "\nPHOTOS CHANGED HERE: THE PRODUCT'S GO, AS A PUSH SENDS THEM\n" . str_repeat( '=', 124 ) . "\n";

// The same photos in another order: the main photo changed.
coin( 720, array( 7202, 7201 ) );
update_post_meta( 7201, NUMBER_META, num( 'mn', 1 ) );
update_post_meta( 7202, NUMBER_META, num( 'mn', 2 ) );
list( $res, $calls, $uploads ) = relist_now( 720, ended_answer( '110000000720', array( g_url( num( 'mn', 1 ) ), g_url( num( 'mn', 2 ) ) ) ) );
check( 'the same photos in another order (another main photo): sent, in the product\'s order', pictures_in( $calls[1][1] ?? '' ), epss( array( 7202, 7201 ) ) );

// A photo added here; one taken off here.
coin( 721, array( 7111, 7112, 7213 ) );
list( $res, $calls, $uploads ) = relist_now( 721, ended_answer( '110000000721', array( g_url( num( 'up', 1 ) ), g_url( num( 'up', 2 ) ) ) ) );
check( 'a photo added to the product: all three sent, only the new one uploaded (the others\' copies cached)', array( pictures_in( $calls[1][1] ?? '' ), $uploads ), array( epss( array( 7111, 7112, 7213 ) ), array( 'coin-7111.jpg', 'coin-7112.jpg', 'coin-7213.jpg' ) ) );
coin( 722, array( 7111 ) );
update_post_meta( 7111, NUMBER_META, num( 'up', 1 ) );
list( $res, $calls, $uploads ) = relist_now( 722, ended_answer( '110000000722', array( g_url( num( 'up', 1 ) ), g_url( num( 'up', 2 ) ) ) ) );
check( 'a photo taken off the product: the one left sent', pictures_in( $calls[1][1] ?? '' ), epss( array( 7111 ) ) );

// A number learnt by place for another product counts for that product alone.
coin( 723, array( 7121 ) );
list( $res, $calls, $uploads ) = relist_now( 723, ended_answer( '110000000723', array( z_url( num( 'cp', 1 ) ) ) ) );
check( 'a photo known by place to another product\'s listing only: not this listing\'s - sent', count( pictures_in( $calls[1][1] ?? '' ) ), 1 );

// With the setting off: the website's addresses, nothing uploaded.
hosting( false );
coin( 724, array( 7241, 7242 ) );
list( $res, $calls, $uploads ) = relist_now( 724, ended_answer( '110000000724', $SOLD ) );
check( '"Host listing images on eBay?" off: the website\'s addresses, nothing uploaded', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000724', 1, '', pics( sites( array( 7241, 7242 ) ) ) ), array() ) );
check( '  recorded with the addresses sent', record_of( 724 ), array( '110000000001', array( 7241, 7242 ), sites( array( 7241, 7242 ) ), array( 7241, 7242 ), 0 ) );
hosting( true );

echo "\nWHEN IT CANNOT BE TOLD: NOTHING MORE IS SENT, AS BEFORE\n" . str_repeat( '=', 124 ) . "\n";

coin( 730, array( 7301 ) );
list( $res, $calls, $uploads ) = relist_now( 730, ended_answer( '110000000730', null ) );
check( 'eBay\'s answer gives no pictures: as before, nothing uploaded, nothing recorded', array( $calls[1][1] ?? null, $uploads, get_post_meta( 730, PUSHED_META, true ) ), array( relist_body( '110000000730', 1 ), array(), '' ) );
coin( 731, array() );
list( $res, $calls, $uploads ) = relist_now( 731, ended_answer( '110000000731', $SOLD ) );
check( 'the product has no photos: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000731', 1 ), array() ) );
coin( 732, array( 7321 ) );
list( $res, $calls, $uploads ) = relist_now( 732, ended_answer( '110000000732', array( 'https://i.ebayimg.com/7321.jpg' ) ) );
check( 'a picture whose address carries no number: as before', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000732', 1 ), array() ) );
coin( 732, array( 7321 ) );
list( $res, $calls, $uploads ) = relist_now( 732, ended_answer( '110000000732', array( g_url( num( 'x', 1 ) ), 'https://elsewhere.example/a.jpg' ) ) );
check( '  nor one among others that do', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000732', 1 ), array() ) );

echo "\nA PHOTO THAT CANNOT GO: REFUSED BEFORE EBAY, NEVER RELISTED WITH THE OLD PHOTOS\n" . str_repeat( '=', 124 ) . "\n";

// One photo already on eBay (kept there at import), another whose upload eBay
// refuses: a push leaves it out, as eBay does not take a mix.
coin( 740, array( 7401, 7402 ) );
$GLOBALS['W']['images'][7401] = g_url( num( 'kp', 2 ) );
update_post_meta( 7401, '_tcgiant_external_url', g_url( num( 'kp', 2 ) ) );
update_post_meta( 7401, '_tcgiant_source_url', g_url( num( 'kp', 2 ) ) );
$API->refuse['coin-7402.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
$from                         = count( $GLOBALS['W']['log'] );
list( $res, $calls, $uploads ) = relist_now( 740, ended_answer( '110000000740', $SOLD ) );
unset( $API->refuse['coin-7402.jpg'] );
$refusal = 'its photos changed here since eBay listing 110000000740 had them, but photo #7402 (eBay refused the file) could not be put on eBay, and its other photos are on eBay, which does not accept a mix, so a relist would put it back up with its old photos. Use Update eBay Listing to list it with its photos as they are now';
check( 'a photo left out beside one on eBay: refused before eBay - only the GetItem it reads the listing with', array( code_of( $res ), array_column( $calls, 0 ) ), array( 'relist_refused', array( 'GetItem' ) ) );
check( '  in plain words, saying to use Update eBay Listing', is_wp_error( $res ) ? $res->get_error_message() : '', $refusal );
check( '  the log says so, and nothing else of the photos', warnings_since( $from ), array( 'Bulk relist: WC #740 was not relisted: ' . $refusal . '.' ) );
check( '  nothing recorded, the product still on its ended listing', array( get_post_meta( 740, PUSHED_META, true ), get_post_meta( 740, '_ebay_item_id', true ) ), array( '', '110000000740' ) );
// Two left out.
coin( 741, array( 7401, 7412, 7413 ) );
$GLOBALS['W']['images'][7401] = g_url( num( 'kp', 2 ) );
$API->refuse['coin-7412.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
$API->refuse['coin-7413.jpg'] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted.' );
list( $res, $calls, $uploads ) = relist_now( 741, ended_answer( '110000000741', $SOLD ), 'Auto-Relist' );
$API->refuse = array();
check( '  two left out, by Auto-Relist: a refused row, both named with why', array( code_of( $res ), is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'photos #7412 (eBay refused the file), #7413 (Daily API call budget exhausted) could not be put on eBay' ), logged( 'Auto-Relist: WC #741 was not relisted: its photos changed here', 'warning' ) ), array( 'relist_refused', true, true ) );

// The main photo has nothing to send: no address and no copy on eBay.
hosting( false );
coin( 742, array( 7421, 7422 ) );
unset( $GLOBALS['W']['images'][7421] );
$from = count( $GLOBALS['W']['log'] );
list( $res, $calls, $uploads ) = relist_now( 742, ended_answer( '110000000742', $SOLD ) );
check( 'the main photo cannot go (deleted: no address, no copy on eBay): refused before eBay', array( code_of( $res ), array_column( $calls, 0 ) ), array( 'relist_refused', array( 'GetItem' ) ) );
check( '  in plain words', is_wp_error( $res ) ? $res->get_error_message() : '', 'its photos changed here since eBay listing 110000000742 had them, but its main photo (#7421) could not be put on eBay (it has no address on your website and no copy on eBay), so a relist would put it back up with its old photos. Use Update eBay Listing to list it with its photos as they are now, once the main photo can be put on eBay, or choose another main photo' );
check( '  only the refusal said, not the push\'s "left out of the listing"', count( warnings_since( $from ) ), 1 );
hosting( true );

// Nothing to send at all.
coin( 743, array( 7431 ), array(), array( 'image_id' => 0, 'gallery' => array( 7431 ) ) );
unset( $GLOBALS['W']['images'][7431], $GLOBALS['W']['files'][7431] );
list( $res, $calls, $uploads ) = relist_now( 743, ended_answer( '110000000743', $SOLD ) );
check( 'no main photo, and its gallery\'s one photo has nothing to send: refused, never sent with no photos', array( code_of( $res ), array_column( $calls, 0 ), is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'but none of them could be sent to eBay' ) ), array( 'relist_refused', array( 'GetItem' ), true ) );

// Not refused: a main photo whose file is offloaded goes from the website
// with the others, as a push sends it.
coin( 744, array( 7441, 7442 ) );
unset( $GLOBALS['W']['files'][7441] );
$from = count( $GLOBALS['W']['log'] );
list( $res, $calls, $uploads ) = relist_now( 744, ended_answer( '110000000744', $SOLD ) );
check( 'the main photo\'s file offloaded, nothing on eBay: all from the website, never a mix', array( code_of( $res ), pictures_in( $calls[1][1] ?? '' ) ), array( 'relisted', sites( array( 7441, 7442 ) ) ) );
check( '  said in the relist\'s own words', warnings_since( $from ), array( 'Bulk relist: photo #7441 of WC #744 could not be put on eBay (its file is not on this server), so this listing\'s photos are all sent from your website. eBay does not accept a mix.' ) );

echo "\nEVERY RELIST: AUCTIONS, VARIATIONS, PRICES, EBAY'S REFUSAL, AND A PUSH'S OWN\n" . str_repeat( '=', 124 ) . "\n";

// An auction: RelistItem, its photos after what it already sent.
coin( 750, array( 7501 ), array( '_ebay_listing_type' => 'Chinese' ) );
list( $res, $calls, $uploads ) = relist_now( 750, ended_answer( '110000000750', $SOLD, 'Chinese' ) );
check( 'an auction: RelistItem with the product\'s photos, nothing else added', array( array_column( $calls, 0 ), $calls[1][1] ?? null ), array( array( 'GetItem', 'RelistItem' ), "\n<Item>\n<ItemID>110000000750</ItemID>\n<UUID/>\n" . pics( epss( array( 7501 ) ) ) . "\n</Item>" ) );
check( '  recorded the same', record_of( 750 )[1], array( 7501 ) );
coin( 751, array( 7111 ), array( '_ebay_listing_type' => 'Chinese' ) );
update_post_meta( 7111, NUMBER_META, num( 'up', 1 ) );
list( $res, $calls, $uploads ) = relist_now( 751, ended_answer( '110000000751', array( g_url( num( 'up', 1 ) ) ), 'Chinese' ) );
check( '  unchanged: RelistItem as before 3.32.1', $calls[1][1] ?? null, "\n<Item>\n<ItemID>110000000751</ItemID>\n<UUID/>\n</Item>" );

// A push's relist of an imported auction sends its own item's photos
// (3.28.8): the relist decides nothing of them.
coin( 752, array( 7521 ), array( '_ebay_listing_type' => 'Chinese' ) );
$changes = "<Title>1921 Morgan Dollar 752</Title>\n<Description><![CDATA[A Morgan dollar.]]></Description>\n";
$answer  = ended_answer( '110000000752', $SOLD, 'Chinese' );
list( $res, $calls, $uploads ) = relist_now( 752, $answer, 'Push', $answer['Item'], $changes );
check( 'a push\'s relist of an auction: its own Title and Description, no photos decided, nothing uploaded, nothing recorded by the relist', array( array_column( $calls, 0 ), $calls[0][1] ?? null, $uploads, get_post_meta( 752, PUSHED_META, true ) ), array( array( 'RelistItem' ), "\n<Item>\n<ItemID>110000000752</ItemID>\n<UUID/>\n" . rtrim( $changes ) . "\n</Item>", array(), '' ) );

// A variation listing: the listing's PictureDetails after its Variations;
// no picture of a variation's own.
$var = coin( 753, array( 7531, 7532 ), array(), array( 'type' => 'variable', 'sku' => 'VAR-753' ), 'Photo_Variable' );
$var->variation_attributes = array( 'size' => array( 'Small' ) );
$var->children             = array( 7539 );
new Photo_Variation( 7539, array( 'sku' => 'VAR-753-S', 'size' => 'Small', 'regular_price' => '10.00', 'stock' => 2 ) );
$one = array( 'Variation' => array( array( 'SKU' => 'VAR-753-S', 'StartPrice' => '10.00', 'Quantity' => '2', 'SellingStatus' => array( 'QuantitySold' => '0' ), 'VariationSpecifics' => array( 'NameValueList' => array( 'Name' => 'size', 'Value' => 'Small' ) ) ) ), 'Pictures' => array( 'VariationSpecificName' => 'size', 'VariationSpecificPictureSet' => array( 'VariationSpecificValue' => 'Small', 'PictureURL' => g_url( num( 'vs', 1 ) ) ) ) );
list( $res, $calls, $uploads ) = relist_now( 753, ended_answer( '110000000753', $SOLD, 'FixedPriceItem', array( 'Variations' => $one ) ) );
$vxml = '<Variations><Variation><SKU>VAR-753-S</SKU><StartPrice>10.00</StartPrice><Quantity>2</Quantity><VariationSpecifics><NameValueList><Name>size</Name><Value>Small</Value></NameValueList></VariationSpecifics></Variation></Variations>';
check( 'a variation listing: the listing\'s PictureDetails after its Variations, which are as before', $calls[1][1] ?? null, relist_body( '110000000753', null, '', pics( epss( array( 7531, 7532 ) ) ), $vxml ) );
check( '  no VariationSpecificPictureSet sent: each variation\'s pictures stay as eBay has them', array( false !== strpos( (string) ( $calls[1][1] ?? '' ), 'VariationSpecificPictureSet' ), false !== strpos( (string) ( $calls[1][1] ?? '' ), '<Pictures>' ) ), array( false, false ) );

// The price and the photos, both changed here: both go, and both are said.
coin( 754, array( 7541 ), array( '_ebay_price_seen' => '12.00' ) );
$GLOBALS['W']['log'] = array();
list( $res, $calls, $uploads ) = relist_now( 754, ended_answer( '110000000754', $SOLD ) );
check( 'a new price and new photos: StartPrice after the quantity, the photos last', $calls[1][1] ?? null, relist_body( '110000000754', 1, '20.00', pics( epss( array( 7541 ) ) ) ) );
check( '  the log gives both', logged( 'Bulk relist: WC #754 relisted on eBay as 110000000001 (was 110000000754), quantity 1, at 20.00, its eBay price changed here since eBay last had it, with the product\'s photos, changed here since the listing had them.' ), true );

// eBay refuses the relist: the uploads' numbers stay on the photos, as a
// push's do, and nothing is recorded.
coin( 755, array( 7551 ) );
$API->answers['RelistFixedPriceItem'] = new WP_Error( 'api_error', 'Item cannot be relisted.' );
list( $res, $calls, $uploads ) = relist_now( 755, ended_answer( '110000000755', $SOLD ) );
unset( $API->answers['RelistFixedPriceItem'] );
check( 'eBay refuses the relist: its error, nothing recorded, the product on its ended listing; the upload\'s number on its photo', array( code_of( $res ), get_post_meta( 755, PUSHED_META, true ), get_post_meta( 755, '_ebay_item_id', true ), numbers( 7551 ) ), array( 'api_error', '', '110000000755', array( 'acoin7551' ) ) );
check( '  and no success line', logged( 'WC #755 relisted' ), false );

// More photos than a listing takes, by Auto-Relist: the first 24, said in
// the relist's own words.
coin( 756, range( 7601, 7626 ) );
$from = count( $GLOBALS['W']['log'] );
list( $res, $calls, $uploads ) = relist_now( 756, ended_answer( '110000000756', $SOLD ), 'Auto-Relist' );
check( '26 new photos, by Auto-Relist: the first 24 sent and recorded', array( count( pictures_in( $calls[1][1] ?? '' ) ), count( record_of( 756 )[1] ?? array() ), count( record_of( 756 )[3] ?? array() ) ), array( 24, 24, 26 ) );
check( '  the push\'s warning, in the relist\'s words', warnings_since( $from ), array( 'Auto-Relist: WC #756 has more photos than the 24 eBay takes in one listing, so the 2 after the first 24 were not sent.' ) );
$tcg_for = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'build_for' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_for->setAccessible( true );
}
check( '  and the exporter\'s own build is a push\'s again after it, refused or not', $tcg_for->getValue( exporter() ), 'Push' );
coin( 757, array( 7571 ) );
update_post_meta( 7571, NUMBER_META, 'acoin7571' );
relist_now( 757, ended_answer( '110000000757', array( g_url( num( 'sold', 1 ) ) ) ) );
coin( 757, array( 7572 ), array( '_ebay_item_id' => '110000000758' ) );
list( $res, $calls, $uploads ) = relist_now( 757, ended_answer( '110000000758', array( g_url( 'acoin7571' ) ) ) );
check( 'its photos changed again before the next relist, in the same request: decided afresh, the new one sent', pictures_in( $calls[1][1] ?? '' ), epss( array( 7572 ) ) );
$tcg_said = method_exists( 'TCGiant_Sync_Exporter', 'said_for' ) ? private_method( 'TCGiant_Sync_Exporter', 'said_for' ) : null;
check( '  and a push\'s own words are as before: a push says "Push:"', $tcg_said ? array( $tcg_said->invoke( null, 'Push' ), $tcg_said->invoke( null, 'Verify' ), $tcg_said->invoke( null, '' ), $tcg_said->invoke( null, 'Bulk relist' ) ) : null, array( 'Push', 'Verify', 'Push', 'Bulk relist' ) );

// A staging copy uploads nothing (a child run, TCGIANT_SYNC_IS_STAGING).
$tcg_out   = array();
$tcg_code  = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child=staging 2>&1', $tcg_out, $tcg_code );
$tcg_child = json_decode( (string) end( $tcg_out ), true );
check( 'on a staging copy: no upload asked for; the cached copy is not mixed with the website\'s, so all go from the website', array( $tcg_code, $tcg_child['staging'] ?? null, $tcg_child['uploads'] ?? null, $tcg_child['pictures'] ?? null ), array( 0, true, array(), sites( array( 7901, 7902 ) ) ) );
check( '  said with the copy\'s reason', $tcg_child['warnings'] ?? null, array( 'Bulk relist: photo #7901 of WC #790 could not be put on eBay (' . ( $tcg_child['why'] ?? '' ) . '), so this listing\'s photos are all sent from your website. eBay does not accept a mix.' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIX ROUND 1 (HIGH-1): COPIES THE OLD LOOP LEFT ARE NEVER PUT ON THE LIVE LISTING\n" . str_repeat( '=', 124 ) . "\n";

// Fix round 2 (R2-L4): the copies named, and what to do - not Image
// Cleanup's list, made once, which may not have the product.
$TAILS_SAID = 'it shows copies of its own photos that a sync downloaded from eBay (photos #8003, #8004), and a relist would put them on eBay beside the photos they copy. Remove the copies from its photos, then use Update eBay Listing';
// The review's P1: the shop's own 8001 and 8002, pushed from the website
// before 3.30.4 (no record); eBay copied them as cp1 and cp2; the sync then
// downloaded those copies as 8003 and 8004 behind them, the day after.
foreach ( array( true, false ) as $host ) {
	hosting( $host );
	$tid = 800 + (int) $host;
	coin( $tid, array( 8001, 8002, 8003, 8004 ), array( '_ebay_export_last_pushed' => '2026-10-20 10:00:00' ) );
	update_post_meta( 8003, '_tcgiant_source_url', g_url( num( 'cp', 1 ) ) );
	update_post_meta( 8004, '_tcgiant_source_url', g_url( num( 'cp', 2 ) ) );
	$GLOBALS['W']['made'][8003] = '2026-10-21 09:00:00';
	$GLOBALS['W']['made'][8004] = '2026-10-21 09:00:01';
	$from = count( $GLOBALS['W']['log'] );
	list( $res, $calls, $uploads ) = relist_now( $tid, ended_answer( '110000000' . $tid, array( z_url( num( 'cp', 1 ) ), z_url( num( 'cp', 2 ) ) ) ), 'Auto-Relist' );
	check( 'P1, the copies behind its photos, eBay hosting ' . ( $host ? 'on' : 'off' ) . ': refused before eBay - only the GetItem, no upload', array( code_of( $res ), array_column( $calls, 0 ), $uploads ), array( 'relist_refused', array( 'GetItem' ), array() ) );
	check( '  in plain words: the copies named, to be removed from its photos, then Update eBay Listing - no list to look in', is_wp_error( $res ) ? $res->get_error_message() : '', $TAILS_SAID );
	check( '  the log says so, and nothing is recorded', array( warnings_since( $from ), get_post_meta( $tid, PUSHED_META, true ), exporter()->relist_decided_photos() ), array( array( 'Auto-Relist: WC #' . $tid . ' was not relisted: ' . $TAILS_SAID . '.' ), '', false ) );
}
hosting( true );
// One photo, one copy behind it, a one-picture listing.
coin( 809, array( 8091, 8092 ), array( '_ebay_export_last_pushed' => '2026-10-20 10:00:00' ) );
update_post_meta( 8092, '_tcgiant_source_url', g_url( num( 'cp', 9 ) ) );
$GLOBALS['W']['made'][8092] = '2026-10-22 09:00:00';
list( $res, $calls, $uploads ) = relist_now( 809, ended_answer( '110000000809', array( z_url( num( 'cp', 9 ) ) ) ) );
check( '  one photo and its one copy: refused the same way', array( code_of( $res ), array_column( $calls, 0 ), $uploads ), array( 'relist_refused', array( 'GetItem' ), array() ) );
check( '  the one copy named', is_wp_error( $res ) ? $res->get_error_message() : '', 'it shows copies of its own photos that a sync downloaded from eBay (photo #8092), and a relist would put them on eBay beside the photos they copy. Remove the copies from its photos, then use Update eBay Listing' );
// Copies in place of its photos ("Overwrite Images" on): each is the
// download of the picture in its place, so the relist sends nothing, as
// before - no copy goes to eBay.
coin( 802, array( 8003, 8004 ), array( '_ebay_export_last_pushed' => '2026-10-20 10:00:00' ) );
photo( 8001 );
list( $res, $calls, $uploads ) = relist_now( 802, ended_answer( '110000000802', array( z_url( num( 'cp', 1 ) ), z_url( num( 'cp', 2 ) ) ) ) );
check( 'copies in place of its photos: the same as the listing - relisted as before, nothing sent', array( code_of( $res ), $calls[1][1] ?? null, $uploads ), array( 'relisted', relist_body( '110000000802', 1 ), array() ) );
// The coin shop's reused product keeping the sold coin's photos behind the
// new ones: imported long before any push, so no copies - it goes.
coin( 803, array( 8031, 8032, 8033, 8034 ) );
update_post_meta( 8033, '_tcgiant_source_url', g_url( num( 'sold', 1 ) ) );
update_post_meta( 8034, '_tcgiant_source_url', g_url( num( 'sold', 2 ) ) );
$GLOBALS['W']['made'][8033] = '2026-09-01 09:00:00';
$GLOBALS['W']['made'][8034] = '2026-09-01 09:00:01';
list( $res, $calls, $uploads ) = relist_now( 803, ended_answer( '110000000803', $SOLD ) );
check( 'the coin shop\'s [new, new, old, old], never pushed: it goes, with its four photos', array( code_of( $res ), count( pictures_in( $calls[1][1] ?? '' ) ) ), array( 'relisted', 4 ) );
coin( 804, array( 8031, 8032, 8033, 8034 ), array( '_ebay_export_last_pushed' => '2026-10-20 10:00:00' ) );
list( $res, $calls, $uploads ) = relist_now( 804, ended_answer( '110000000804', $SOLD ) );
check( '  pushed since, the old photos imported before that push: it goes', array( code_of( $res ), count( pictures_in( $calls[1][1] ?? '' ) ) ), array( 'relisted', 4 ) );

echo "\nFIX ROUND 1 (HIGH-2): RECORDED BEFORE THE CALL, PUT BACK WHEN NOTHING WAS RELISTED\n" . str_repeat( '=', 124 ) . "\n";

$EARLIER = array( 'item' => '110000000805', 'ids' => array( 8051 ), 'urls' => array( eps( 8051 ) ), 'shown' => array( 8051 ), 'time' => 1700000000, 'read' => 1700000500, 'learnt' => 0 );
$refusals = array(
	'eBay refuses it'           => new WP_Error( 'api_error', 'Item cannot be relisted.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '21916250', 'LongMessage' => 'Item cannot be relisted.' ) ) ),
	'a staging copy blocks it'  => new WP_Error( 'staging_blocked', 'Not sent to eBay: this site looks like a staging or development copy.' ),
	'the day\'s calls are spent' => new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.' ),
	'no eBay token'             => new WP_Error( 'no_token', 'No valid eBay token.' ),
);
foreach ( $refusals as $why => $error ) {
	coin( 805, array( 8052 ), array( PUSHED_META => $EARLIER, '_tcgiant_photos_held' => array( 'at' => 1, 'numbers' => array( 'x1' ) ), '_tcgiant_photos_released' => '110000000700' ) );
	$API->answers['RelistFixedPriceItem'] = $error;
	list( $res, $calls, $uploads ) = relist_now( 805, ended_answer( '110000000805', $SOLD ) );
	unset( $API->answers['RelistFixedPriceItem'] );
	check( $why . ': the photos were sent, and the record, held and released marks are put back as they were', array( count( pictures_in( $calls[1][1] ?? '' ) ), get_post_meta( 805, PUSHED_META, true ), get_post_meta( 805, '_tcgiant_photos_held', true ), get_post_meta( 805, '_tcgiant_photos_released', true ) ), array( 1, $EARLIER, array( 'at' => 1, 'numbers' => array( 'x1' ) ), '110000000700' ) );
}
coin( 806, array( 8061 ) );
$API->answers['RelistFixedPriceItem'] = $refusals['eBay refuses it'];
relist_now( 806, ended_answer( '110000000806', $SOLD ) );
unset( $API->answers['RelistFixedPriceItem'] );
check( '  with no record before: none after - no row of the record, held or released marks at all', array( get_post_meta( 806, PUSHED_META, true ), array_intersect( array( PUSHED_META, '_tcgiant_photos_held', '_tcgiant_photos_released' ), array_keys( $GLOBALS['W']['meta'][806] ?? array() ) ) ), array( '', array() ) );
foreach ( array(
	'a timeout'                                => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
	'eBay: error 488, among its errors'        => new WP_Error( 'api_error', 'Duplicate request. | Seen before.', array( 'Ack' => 'Failure', 'Errors' => array( array( 'ErrorCode' => '21917236', 'ShortMessage' => 'Seen before.' ), array( 'ErrorCode' => '488', 'ShortMessage' => 'Duplicate request.' ) ) ) ),
	'eBay: error 488, its one error'           => new WP_Error( 'api_error', 'Duplicate request.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '488', 'ShortMessage' => 'Duplicate request.' ) ) ),
	'eBay: the UUID named in its words'        => new WP_Error( 'api_error', 'The specified UUID has already been used; ListedByRequestAppId=1, item ID=110000000001.' ),
	'eBay: DuplicateInvocationDetails'         => new WP_Error( 'api_error', 'Duplicate request.', array( 'Ack' => 'Failure', 'DuplicateInvocationDetails' => array( 'Status' => 'Success', 'InvocationTrackingID' => '110000000001' ) ) ),
	'an answer that could not be read'         => new WP_Error( 'invalid_xml', 'Invalid XML from eBay Trading API.' ),
) as $why => $error ) {
	coin( 807, array( 8071 ), array( PUSHED_META => $EARLIER, '_tcgiant_photos_held' => array( 'at' => 1, 'numbers' => array( 'x1' ) ), '_tcgiant_photos_released' => '110000000700' ) );
	$API->answers['RelistFixedPriceItem'] = $error;
	list( $res, $calls, $uploads ) = relist_now( 807, ended_answer( '110000000807', $SOLD ) );
	unset( $API->answers['RelistFixedPriceItem'] );
	$kept = record_of( 807 );
	// Fix round 2 (R2-M1): kept spent, as if read - it can only make the
	// next import hold pictures, never learn them by place.
	check( $why . ' - eBay may have relisted it: what was sent stays recorded, against the listing it relisted, spent', array( array_slice( (array) $kept, 0, 4 ), ! empty( $kept[4] ), get_post_meta( 807, '_ebay_item_id', true ) ), array( array( '110000000807', array( 8071 ), epss( array( 8071 ) ), array( 8071 ) ), true, '110000000807' ) );
	check( '  the held and released marks left as they were', array( get_post_meta( 807, '_tcgiant_photos_held', true ), get_post_meta( 807, '_tcgiant_photos_released', true ) ), array( array( 'at' => 1, 'numbers' => array( 'x1' ) ), '110000000700' ) );
}
coin( 808, array( 8081 ) );
list( $res, $calls, $uploads ) = relist_now( 808, ended_answer( '110000000808', $SOLD ) );
// Fix round 2: the record written once eBay has answered is the push's own,
// unread, so the new listing's first read can learn its places.
check( 'taken: recorded against the new listing as a push records it, unread', record_of( 808 ), array( '110000000001', array( 8081 ), epss( array( 8081 ) ), array( 8081 ), 0 ) );

// Fix round 2 (R2-L3, the review's P10): a request sent again after an
// attempt eBay may have had - a timeout, a 5xx (TCGiant_Sync_API::resends())
// - keeps the record whatever eBay's last answer says, here a refusal in
// words that say nothing of a duplicate. Only a request that went once, and
// that eBay plainly refused, is put back.
$tcg_already = new WP_Error( 'api_error', 'This item cannot be relisted. It has already been relisted.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '21916245', 'LongMessage' => 'This item cannot be relisted. It has already been relisted.' ) ) );
foreach ( array( 'GetItem' => 'sent once (only the GetItem before it sent again)', 'RelistFixedPriceItem' => 'sent again after an attempt eBay may have had' ) as $tcg_call => $how ) {
	foreach ( array( 1, 2 ) as $tcg_times ) {
		coin( 850, array( 8501 ), array( PUSHED_META => $EARLIER ) );
		$API->answers['RelistFixedPriceItem'] = $tcg_already;
		$API->resent[ $tcg_call ]             = $tcg_times;
		list( $res, $calls ) = relist_now( 850, ended_answer( '110000000850', $SOLD ) );
		$API->resent = array();
		unset( $API->answers['RelistFixedPriceItem'] );
		$tcg_after = record_of( 850 );
		check( 'P10, eBay saying "already relisted", the relist ' . $how . ' (' . $tcg_times . 'x): ' . ( 'GetItem' === $tcg_call ? 'put back - eBay plainly refused the one request' : 'kept, spent - eBay may have made it' ), array( code_of( $res ), count( pictures_in( $calls[1][1] ?? '' ) ), 'GetItem' === $tcg_call ? get_post_meta( 850, PUSHED_META, true ) : array( $tcg_after[0] ?? null, ! empty( $tcg_after[4] ) ) ), array( 'api_error', 1, 'GetItem' === $tcg_call ? $EARLIER : array( '110000000850', true ) ) );
	}
}

echo "\nFIX ROUND 1 (MEDIUM-1): A PHOTO WHOSE FILE CHANGED UNDER THE SAME ATTACHMENT IS SENT\n" . str_repeat( '=', 124 ) . "\n";

// The review's P2: an imported photo edited in WordPress's image editor.
coin( 810, array( 8101 ) );
update_post_meta( 8101, '_tcgiant_source_url', g_url( num( 'ed', 1 ) ) );
update_post_meta( 8101, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'coin-8101.jpg' ) ) );
update_post_meta( 8101, '_wp_attached_file', '2026/10/coin-8101-e1730800000000.jpg' );
list( $res, $calls, $uploads ) = relist_now( 810, ended_answer( '110000000810', array( z_url( num( 'ed', 1 ) ) ) ) );
check( 'P2: an imported photo edited in WordPress (its sizes backed up, "-e" and 13 digits): sent, as photos_match() says it is not the listing\'s', array( TCGiant_Sync_Ebay_Live::photos_match( array( 8101 ), array( 'pictures' => array( g_url( num( 'ed', 1 ) ) ) ) ), pictures_in( $calls[1][1] ?? '' ) ), array( false, epss( array( 8101 ) ) ) );
coin( 811, array( 8111 ) );
update_post_meta( 8111, '_tcgiant_source_url', g_url( num( 'ed', 2 ) ) );
update_post_meta( 8111, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'coin-8111.jpg' ) ) );
list( $res, $calls, $uploads ) = relist_now( 811, ended_answer( '110000000811', array( z_url( num( 'ed', 2 ) ) ) ) );
check( '  its sizes backed up alone: sent', count( pictures_in( $calls[1][1] ?? '' ) ), 1 );
coin( 812, array( 8121 ) );
update_post_meta( 8121, '_tcgiant_source_url', g_url( num( 'ed', 3 ) ) );
update_post_meta( 8121, '_wp_attached_file', '2026/10/coin-8121-e1730800000001.jpg' );
list( $res, $calls, $uploads ) = relist_now( 812, ended_answer( '110000000812', array( z_url( num( 'ed', 3 ) ) ) ) );
check( '  its "-e" name alone: sent', count( pictures_in( $calls[1][1] ?? '' ) ), 1 );
// The review's P3: pushed with eBay hosting, its file replaced in place.
coin( 820, array( 8201 ) );
update_post_meta( 8201, NUMBER_META, 'acoin8201' );
update_post_meta( 8201, '_tcgiant_eps_url', eps( 8201 ) );
update_post_meta( 8201, '_tcgiant_eps_key', 'stale-mtime' );
update_post_meta( 8201, '_tcgiant_eps_time', time() );
list( $res, $calls, $uploads ) = relist_now( 820, ended_answer( '110000000820', array( z_url( 'acoin8201' ) ) ) );
check( 'P3: an uploaded photo whose file was replaced (its modified time not the upload\'s): uploaded again and sent, as a push would', array( pictures_in( $calls[1][1] ?? '' ), $uploads ), array( epss( array( 8201 ) ), array( 'coin-8201.jpg' ) ) );
coin( 821, array( 8211 ) );
update_post_meta( 8211, NUMBER_META, 'acoin8211' );
update_post_meta( 8211, '_tcgiant_eps_url', eps( 8211 ) );
update_post_meta( 8211, '_tcgiant_eps_key', (string) filemtime( $GLOBALS['W']['files'][8211] ) );
update_post_meta( 8211, '_tcgiant_eps_time', time() );
list( $res, $calls, $uploads ) = relist_now( 821, ended_answer( '110000000821', array( z_url( 'acoin8211' ) ) ) );
check( '  the same file as uploaded: the same - nothing sent, nothing uploaded', array( $calls[1][1] ?? null, $uploads ), array( relist_body( '110000000821', 1 ), array() ) );
unset( $GLOBALS['W']['files'][8211] );
update_post_meta( 821, '_ebay_item_id', '110000000821' );
update_post_meta( 821, '_ebay_listing_status', 'Ended' );
list( $res, $calls, $uploads ) = relist_now( 821, ended_answer( '110000000821', array( z_url( 'acoin8211' ) ) ) );
check( '  its file offloaded (not on this server to compare): the same, as before', $calls[1][1] ?? null, relist_body( '110000000821', 1 ) );
// Learnt by place from a push from the website, then edited in WordPress:
// its address is no longer the one that push sent.
coin( 822, array( 8221 ), array( PUSHED_META => array( 'item' => '110000000822', 'ids' => array( 8221 ), 'urls' => array( site( 8221 ) ), 'shown' => array( 8221 ), 'time' => time(), 'read' => time(), 'learnt' => 1 ) ) );
update_post_meta( 8221, NUMBER_META, 'place:' . num( 'cp', 21 ) );
$GLOBALS['W']['images'][8221] = 'https://shop.example/wp-content/uploads/2026/10/coin-8221-e1730800000002.jpg';
list( $res, $calls, $uploads ) = relist_now( 822, ended_answer( '110000000822', array( z_url( num( 'cp', 21 ) ) ) ) );
check( 'a photo learnt by place whose address is no longer the one its push sent (edited since): sent', pictures_in( $calls[1][1] ?? '' ), epss( array( 8221 ) ) );

echo "\nFIX ROUND 1 (LOW-1): A ONE-PICTURE LISTING GIVEN ONLY AS ITS ORIGINAL ON THE WEBSITE\n" . str_repeat( '=', 124 ) . "\n";

hosting( false );
coin( 830, array( 8302 ) );
list( $res, $calls, $uploads ) = relist_now( 830, ended_answer( '110000000830', null, 'FixedPriceItem', array( 'PictureDetails' => array( 'ExternalPictureURL' => site( 8301 ) ) ) ) );
check( 'P4: ExternalPictureURL only, the old photo\'s: the new photo sent', pictures_in( $calls[1][1] ?? '' ), sites( array( 8302 ) ) );
coin( 831, array( 8311 ) );
list( $res, $calls, $uploads ) = relist_now( 831, ended_answer( '110000000831', null, 'FixedPriceItem', array( 'PictureDetails' => array( 'ExternalPictureURL' => site( 8311 ) . '?v=2' ) ) ) );
check( '  the product\'s one photo at that address: the same, nothing sent', $calls[1][1] ?? null, relist_body( '110000000831', 1 ) );
coin( 832, array( 8311, 8312 ) );
list( $res, $calls, $uploads ) = relist_now( 832, ended_answer( '110000000832', null, 'FixedPriceItem', array( 'PictureDetails' => array( 'ExternalPictureURL' => site( 8311 ) ) ) ) );
check( '  a photo added since: both sent', pictures_in( $calls[1][1] ?? '' ), sites( array( 8311, 8312 ) ) );
coin( 833, array() );
list( $res, $calls, $uploads ) = relist_now( 833, ended_answer( '110000000833', null, 'FixedPriceItem', array( 'PictureDetails' => array( 'ExternalPictureURL' => site( 8311 ) ) ) ) );
check( '  no photos on the product: cannot tell, nothing sent, as before (LOW-2: kept as built)', $calls[1][1] ?? null, relist_body( '110000000833', 1 ) );
hosting( true );

echo "\nFIX ROUND 1 (LOW-3, LOW-6): A REFUSAL REMEMBERED FOR AUTO-RELIST; EBAY'S PICTURES KEPT WHEN NONE WERE SENT\n" . str_repeat( '=', 124 ) . "\n";

$memo = TCGiant_Sync_Exporter::RELIST_PHOTOS_MEMO;
hosting( false );
coin( 840, array( 8401, 8402 ) );
unset( $GLOBALS['W']['images'][8401] );
relist_now( 840, ended_answer( '110000000840', $SOLD ), 'Auto-Relist' );
$state = TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 840 ) );
check( 'refused after its photos were decided: the exporter\'s own build a push\'s again', $tcg_for->getValue( exporter() ), 'Push' );
check( 'a refusal that lasts until the photos change (the main photo has nothing to send): remembered against the listing and the photos, with when (fix round 2)', 1 === preg_match( '/^110000000840>' . $state . '>(\d+)$/', (string) get_post_meta( 840, $memo, true ), $tcg_when ) && abs( (int) $tcg_when[1] - time() ) < 5, true );
check( '  Auto-Relist leaves it (relist_photos_refused())', TCGiant_Sync_Exporter::relist_photos_refused( wc_get_product( 840 ), '110000000840' ), true );
coin( 840, array( 8402, 8401 ), array( $memo => '110000000840>' . $state ) );
check( '  the photos moved: another state', TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 840 ) ) !== $state, true );
coin( 841, array( 8411 ) );
check( '  the photo hosting switched: another state', ( function () {
	$was = TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 841 ) );
	hosting( true );
	$now = TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 841 ) );
	hosting( false );
	return $now !== $was;
} )(), true );
check( '  a file replaced: another state', ( function () {
	$was = TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 841 ) );
	touch( $GLOBALS['W']['files'][8411], time() + 120 );
	clearstatcache();
	return TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 841 ) ) !== $was;
} )(), true );
hosting( true );
coin( 842, array( 8421, 8422 ) );
$GLOBALS['W']['images'][8421] = g_url( num( 'kp', 42 ) );
$API->refuse['coin-8422.jpg'] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted.' );
list( $res ) = relist_now( 842, ended_answer( '110000000842', $SOLD ), 'Auto-Relist' );
unset( $API->refuse['coin-8422.jpg'] );
check( 'a refusal for an upload refused for the day\'s calls, which pass: refused, not remembered - the next run tries again', array( code_of( $res ), get_post_meta( 842, $memo, true ) ), array( 'relist_refused', '' ) );
$API->refuse['coin-8422.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
relist_now( 842, ended_answer( '110000000842', $SOLD ), 'Auto-Relist' );
unset( $API->refuse['coin-8422.jpg'] );
check( '  eBay refusing the file itself, which lasts: remembered', 0 === strpos( (string) get_post_meta( 842, $memo, true ), '110000000842>' ), true );

// Fix round 2 (R2-L2, the review's P11): an upload eBay answered with Ack
// Failure, coded as the real upload_picture() now codes it
// (TCGiant_Sync_API::upload_refusal(); test-call-budget.php sends each
// through upload_picture() itself). Only a refusal of the picture is
// remembered; eBay's call limit, a token it will not take and a failure on
// its side pass, and the next run tries again.
foreach ( array(
	'eBay\'s call limit (518), next to a photo already on eBay' => array( array( 'ErrorCode' => '518', 'ShortMessage' => 'Call usage limit has been reached.', 'LongMessage' => 'Calls to this call have exceeded the call limit.', 'ErrorClassification' => 'RequestError', 'SeverityCode' => 'Error' ), 'rate_limited', false ),
	'eBay\'s daily limit (10007)'                              => array( array( 'ErrorCode' => '10007', 'ShortMessage' => 'Internal error to the application.', 'LongMessage' => 'Internal error to the application.', 'ErrorClassification' => 'SystemError', 'SeverityCode' => 'Error' ), 'rate_limited', false ),
	'a token eBay will not take (931)'                         => array( array( 'ErrorCode' => '931', 'ShortMessage' => 'Auth token is invalid.', 'LongMessage' => 'Validation of the authentication token in API request failed.', 'ErrorClassification' => 'RequestError', 'SeverityCode' => 'Error' ), 'no_token', false ),
	'a sign-in eBay says expired (17470), by its code'          => array( array( 'ErrorCode' => '17470', 'ShortMessage' => 'Please login again now.', 'LongMessage' => 'Please login again now. Your security session has expired.', 'ErrorClassification' => 'RequestError', 'SeverityCode' => 'Error' ), 'no_token', false ),
	'a failure on eBay\'s side (SystemError)'                  => array( array( 'ErrorCode' => '10000', 'ShortMessage' => 'Service unavailable.', 'LongMessage' => 'The picture service is temporarily unavailable.', 'ErrorClassification' => 'SystemError', 'SeverityCode' => 'Error' ), 'upload_unavailable', false ),
	'the picture itself (RequestError)'                        => array( array( 'ErrorCode' => '21919137', 'ShortMessage' => 'Invalid picture.', 'LongMessage' => 'The picture file is not a supported image.', 'ErrorClassification' => 'RequestError', 'SeverityCode' => 'Error' ), 'upload_failed', true ),
) as $why => $said ) {
	coin( 845, array( 8451, 8452 ) );
	$GLOBALS['W']['images'][8451] = g_url( num( 'kq', 45 ) );
	$refusal                      = TCGiant_Sync_API::upload_refusal( array( 'Ack' => 'Failure', 'Errors' => $said[0] ) );
	$API->refuse['coin-8452.jpg'] = $refusal;
	list( $res ) = relist_now( 845, ended_answer( '110000000845', $SOLD ), 'Auto-Relist' );
	unset( $API->refuse['coin-8452.jpg'] );
	check( 'P11, ' . $why . ': coded ' . $said[1] . ', in eBay\'s words; refused, ' . ( $said[2] ? 'remembered' : 'not remembered - the next run tries again' ), array( $refusal->get_error_code(), $refusal->get_error_message(), code_of( $res ), '' !== (string) get_post_meta( 845, $memo, true ) ), array( $said[1], $said[0]['LongMessage'], 'relist_refused', $said[2] ) );
}

// A memo lasts RELIST_PHOTOS_MEMO_DAYS (fix round 2): one remembered by
// mistake stops Auto-Relist a week at most.
coin( 846, array( 8461 ) );
$tcg_state = TCGiant_Sync_Exporter::relist_photos_state( wc_get_product( 846 ) );
$tcg_left  = array();
foreach ( array( time(), time() - 6 * 86400, time() - 7 * 86400 - 60, null ) as $tcg_at ) {
	update_post_meta( 846, $memo, '110000000846>' . $tcg_state . ( null === $tcg_at ? '' : '>' . $tcg_at ) );
	$tcg_left[] = TCGiant_Sync_Exporter::relist_photos_refused( wc_get_product( 846 ), '110000000846' );
}
check( 'a memo written today or 6 days ago: Auto-Relist leaves the product; just over 7 days ago, or undated (fix round 1\'s form): it asks again', $tcg_left, array( true, true, false, false ) );
update_post_meta( 846, $memo, '110000000846>' . $tcg_state . '>' . time() );
check( '  of another listing: asked again', TCGiant_Sync_Exporter::relist_photos_refused( wc_get_product( 846 ), '110000000847' ), false );
check( '  the copies left by the old loop: remembered', 0 === strpos( (string) get_post_meta( 801, $memo, true ), '110000000801>' ), true );
coin( 843, array( 8431 ), array( $memo => '110000000843>stale' ) );
relist_now( 843, ended_answer( '110000000843', $SOLD ) );
check( '  relisted at last: forgotten', get_post_meta( 843, $memo, true ), '' );
check( 'the run\'s memo is read by Auto-Relist before it asks eBay anything (test-relist-guard.php runs it)', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-cron.php' ), 'if ( $pictured && TCGiant_Sync_Exporter::relist_photos_refused( $pictured, (string) $ebay_item_id ) ) {' ), true );

// The review's LOW-6: an unchanged relist keeps what eBay showed.
coin( 844, array( 7111, 7112 ) );
update_post_meta( 7111, NUMBER_META, num( 'up', 1 ) );
update_post_meta( 7112, NUMBER_META, num( 'up', 2 ) );
relist_now( 844, ended_answer( '110000000844', array( g_url( num( 'up', 1 ) ), g_url( num( 'up', 2 ) ) ) ) );
$live = get_post_meta( 844, '_ebay_live', true );
check( 'relisted with nothing sent: eBay\'s record moves to the new listing with its pictures, which are still eBay\'s', array( $live['item_id'] ?? null, $live['pictures'] ?? null, exporter()->relist_decided_photos() ), array( '110000000001', array( g_url( num( 'up', 1 ) ), g_url( num( 'up', 2 ) ) ), false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE LOOP: WHAT A RELIST SENDS, THE NEXT IMPORT KNOWS - NOTHING DOWNLOADED (3.30.4 MUST NOT COME BACK)\n" . str_repeat( '=', 124 ) . "\n";

/** Run test-own-photos.php's world as a child, $in handed over in a file: its JSON answer. */
function own_world( $mode, array $in ) {
	static $n = 0;
	$file = str_replace( '\\', '/', $GLOBALS['DIR'] ) . '/' . $mode . '-' . ( ++$n ) . '.json';
	file_put_contents( $file, json_encode( $in ) );
	$out  = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/test-own-photos.php' ) . ' ' . escapeshellarg( '--child=' . $mode . ':' . $file ) . ' 2>&1' );
	$got  = json_decode( (string) $out, true );
	if ( ! is_array( $got ) ) {
		echo "  (the child said: " . substr( (string) $out, 0, 600 ) . ")\n";
		return array();
	}
	return $got;
}

/** Every meta row the template world holds for these posts. */
function meta_now( array $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		$out[ $id ] = $GLOBALS['W']['meta'][ $id ] ?? array();
	}
	return $out;
}

/** What changed between two meta_now(): per post, set, add (a number kept), delete. */
function delta( array $before, array $after ) {
	$out = array();
	foreach ( $after as $id => $rows ) {
		foreach ( $rows as $key => $value ) {
			if ( ! array_key_exists( $key, $before[ $id ] ?? array() ) || json_encode( $before[ $id ][ $key ] ) !== json_encode( $value ) ) {
				if ( NUMBER_META === $key ) {
					$out[ $id ]['add'][ $key ] = array( $value );
				} else {
					$out[ $id ]['set'][ $key ] = $value;
				}
			}
		}
		foreach ( array_keys( $before[ $id ] ?? array() ) as $key ) {
			if ( ! array_key_exists( $key, $rows ) ) {
				$out[ $id ]['delete'][] = $key;
			}
		}
	}
	return $out;
}

/**
 * The whole loop: the shop (imported, the coin sold, new photos), the relist
 * here, then the import of the new listing as eBay shows it after what the
 * relist sent, twice.
 *
 * $as_before plays 3.32.0's relist, which sent no photos: the new listing
 * shows the sold coin's pictures. $pushed: the sold coin's listing was made
 * by a push with eBay hosting, its pictures the old photos' uploads.
 * $lost (fix round 1): eBay made the relist but its answer was lost - a
 * 'timeout', or the same request sent again, which eBay answers as a UUID
 * used before ('resend'), or in words that say nothing of a duplicate
 * ('resent', fix round 2) - and the import then follows the relist on to its
 * new number, as TCGiant_Sync_Inventory::follow_relist() does.
 *
 * @return array [ shop, what the relist sent, the import's answer, the relist's answer, the record's listing after it ]
 */
function loop( $host, $overwrite, $shape = 1, $as_before = false, $pushed = false, $lost = '' ) {
	$settings = $overwrite ? array( 'overwrite_images' => '1' ) : array();
	$shop     = own_world( 'relist-shop', array( 'settings' => $settings, 'tag' => 'sold', 'old' => 2, 'new' => 2, 'pushed' => $pushed ) );
	if ( ! $shop ) {
		return array( array(), array(), array() );
	}
	list( $pid, $ids ) = shop_here( $shop, $host, $settings );

	$before = meta_now( $ids );
	$ended  = (string) get_post_meta( $pid, '_ebay_item_id', true );
	if ( 'timeout' === $lost ) {
		$GLOBALS['API']->answers['RelistFixedPriceItem'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received' );
	} elseif ( 'resend' === $lost ) {
		$GLOBALS['API']->answers['RelistFixedPriceItem'] = new WP_Error( 'api_error', 'The specified UUID has already been used; ListedByRequestAppId=1, item ID=110000000001.', array( 'Ack' => 'Failure', 'Errors' => array( 'ShortMessage' => 'Duplicate UUID.', 'LongMessage' => 'The specified UUID has already been used; ListedByRequestAppId=1, item ID=110000000001.', 'ErrorCode' => '488', 'SeverityCode' => 'Error' ) ) );
	} elseif ( 'resent' === $lost ) {
		// Fix round 2 (the review's P10): sent again after a timeout, eBay
		// having made the relist, and answered in words that say nothing of
		// a duplicate.
		$GLOBALS['API']->resent['RelistFixedPriceItem']  = 1;
		$GLOBALS['API']->answers['RelistFixedPriceItem'] = new WP_Error( 'api_error', 'This item cannot be relisted. It has already been relisted.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '21916245', 'LongMessage' => 'This item cannot be relisted. It has already been relisted.' ) ) );
	}
	list( $res, $calls ) = relist_now( $pid, ended_answer( $ended, $shop['listing'] ) );
	unset( $GLOBALS['API']->answers['RelistFixedPriceItem'] );
	$GLOBALS['API']->resent = array();
	$sent   = pictures_in( $calls[1][1] ?? '' );
	$delta  = delta( $before, meta_now( $ids ) );
	$record = get_post_meta( $pid, PUSHED_META, true );
	if ( '' !== $lost ) {
		$delta[ $pid ]['follow'] = array( $ended, '110000000001' );
	}
	if ( $as_before ) {
		// 3.32.0's relist: the listing moved on, nothing of the photos.
		$sent  = array();
		$delta = array( $pid => array( 'set' => array( '_ebay_item_id' => (string) get_post_meta( $pid, '_ebay_item_id', true ) ) ) );
	}

	// eBay, after the relist: the pictures it was sent, as GetItem gives
	// them - its uploads' own, or its copies of the website's under numbers
	// of its own, one each (shape 1) or the first left out (shape 2); none
	// sent, the sold coin's.
	if ( ! $sent ) {
		// Read again, in the address form GetItem gives today: as the
		// shop's import did read them, so the image batch looks at them.
		$shows = array_map( function ( $url ) { return g_url( TCGiant_Sync_Own_Photos::number( $url ) ); }, $shop['listing'] );
	} elseif ( '' !== TCGiant_Sync_Own_Photos::number( $sent[0] ) ) {
		$shows = array_map( function ( $url ) { return z_url( TCGiant_Sync_Own_Photos::number( $url ) ); }, $sent );
	} else {
		$shows = array();
		foreach ( array_keys( $sent ) as $i ) {
			$shows[] = z_url( num( 'cp', $pid * 10 + $i ) );
		}
		if ( 2 === $shape ) {
			array_shift( $shows );
		}
	}

	$imported = own_world( 'relist-import', array( 'settings' => $settings, 'product' => $pid, 'delta' => $delta, 'reads' => array( $shows, $shows ) ) );
	return array( $shop, $sent, $imported, code_of( $res ), is_array( $record ) ? (string) ( $record['item'] ?? '' ) : '', $ended );
}

/**
 * The shop relist_shop() made, in this world: its meta, its photos on the
 * website, and the new photos' files here to upload; the product, and the
 * photo-hosting setting.
 *
 * @return array [ product, every post of it ]
 */
function shop_here( array $shop, $host, array $settings ) {
	$pid = (int) $shop['product'];
	$ids = array_merge( array( $pid ), $shop['old'], $shop['new'] );

	foreach ( $ids as $id ) {
		$GLOBALS['W']['meta'][ $id ] = array();
		foreach ( (array) ( $shop['meta'][ $id ] ?? array() ) as $key => $values ) {
			$GLOBALS['W']['meta'][ $id ][ $key ] = end( $values );
		}
	}
	foreach ( array_merge( $shop['old'], $shop['new'] ) as $id ) {
		$GLOBALS['W']['images'][ $id ] = 'https://shop.example/wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file', true );
	}
	foreach ( $shop['new'] as $id ) {
		$file = $GLOBALS['DIR'] . '/new-coin-' . $id . '.jpg';
		file_put_contents( $file, 'JPEG new ' . $id );
		$GLOBALS['W']['files'][ $id ] = $file;
	}
	$gallery = (string) get_post_meta( $pid, '_product_image_gallery', true );
	new WC_Product( $pid, array( 'name' => '1921 Morgan Dollar', 'description' => 'A Morgan dollar.', 'regular_price' => '20.00', 'stock' => 1, 'sku' => 'MD-' . $pid, 'image_id' => (int) get_post_meta( $pid, '_thumbnail_id', true ), 'gallery' => '' === $gallery ? array() : array_map( 'intval', explode( ',', $gallery ) ) ) );
	update_option( 'tcgiant_sync_ebay_settings', array_merge( $settings, array( 'host_images_on_ebay' => $host ? '1' : '0' ) ) );

	return array( $pid, $ids );
}

/** The checks every loop must pass: each read downloads nothing, makes nothing, moves nothing. */
function nothing_came_back( $label, array $shop, array $imported ) {
	$reads = $imported['reads'] ?? array();
	check( $label . ': nothing downloaded, by either read', array_map( function ( $r ) { return $r['downloads']; }, $reads ), array( array(), array() ) );
	check( '  no new attachment, no new file', array_map( function ( $r ) { return array( $r['attachments'], $r['files'] ); }, $reads ), array( array( $shop['attachments'] ?? -1, $imported['files'] ?? -1 ), array( $shop['attachments'] ?? -1, $imported['files'] ?? -1 ) ) );
	check( '  the product shows its new photos, main photo first, after each read', array_map( function ( $r ) { return $r['shown']; }, $reads ), array( $shop['new'] ?? null, $shop['new'] ?? null ) );
}

// With eBay hosting: the uploads' own numbers.
foreach ( array( false, true ) as $ow ) {
	list( $shop, $sent, $imported, $code ) = loop( true, $ow );
	check( ( $ow ? 'Overwrite Images on' : 'Overwrite Images off' ) . ', eBay hosting: the relist sent the new photos, uploaded', array( $code, count( $sent ), '' !== TCGiant_Sync_Own_Photos::number( $sent[0] ?? '' ) ), array( 'relisted', 2, true ) );
	nothing_came_back( '  the import of the new listing', $shop, $imported );
	check( '  each known by its upload\'s number', array_values( $imported['reads'][0]['numbers'] ?? array() ), array_map( function ( $id ) { return array( 'acoin' . $id ); }, $shop['new'] ?? array() ) );
}

// From the website: eBay's own copies, in both of GetItem's shapes.
foreach ( array( false, true ) as $ow ) {
	list( $shop, $sent, $imported, $code ) = loop( false, $ow, 1 );
	check( ( $ow ? 'Overwrite Images on' : 'Overwrite Images off' ) . ', from the website, GetItem giving a picture each: the relist sent the website\'s addresses', array( $code, count( $sent ), TCGiant_Sync_Own_Photos::number( $sent[0] ?? '' ) ), array( 'relisted', 2, '' ) );
	nothing_came_back( '  the import of the new listing', $shop, $imported );
	check( '  eBay\'s copies learnt by place, by the first read after the relist - its record, written once eBay answered, the push\'s own (fix round 2)', array( $imported['reads'][0]['read'] ?? null, $imported['reads'][0]['learnt'] ?? null, array_values( $imported['reads'][1]['numbers'] ?? array() ) ), array( true, 2, array( array( 'place:' . num( 'cp', (int) ( $shop['product'] ?? 0 ) * 10 ) ), array( 'place:' . num( 'cp', (int) ( $shop['product'] ?? 0 ) * 10 + 1 ) ) ) ) );

	list( $shop, $sent, $imported, $code ) = loop( false, $ow, 2 );
	nothing_came_back( ( $ow ? 'Overwrite Images on' : 'Overwrite Images off' ) . ', from the website, GetItem giving one fewer (the first only as ExternalPictureURL)', $shop, $imported );
	check( '  held, not downloaded: nothing learnt that could be wrong', array( $imported['reads'][0]['held'] ?? null, $imported['reads'][0]['learnt'] ?? null ), array( true, 0 ) );
}

// The sold coin's listing made by a push with eBay hosting: its photos
// still carry their uploads' numbers.
list( $shop, $sent, $imported, $code ) = loop( true, false, 1, false, true );
check( 'the sold coin\'s listing pushed with eBay hosting (its photos known by number): the relist sent the new photos', array( $code, count( $sent ) ), array( 'relisted', 2 ) );
nothing_came_back( '  the import of the new listing', $shop, $imported );

// What 3.32.0 left: the relist sent nothing, eBay kept the sold coin's
// pictures, and the import brought the sold coin back.
list( $shop, $sent, $imported ) = loop( true, false, 1, true, true );
check( 'as 3.32.0 left it - no photos sent, the listing back up with the sold coin\'s - that listing pushed: its pictures taken for the old photos, shown again behind the new ones', array( $imported['reads'][0]['downloads'] ?? null, $imported['reads'][0]['shown'] ?? null ), array( array(), array_merge( $shop['new'] ?? array(), $shop['old'] ?? array() ) ) );
list( $shop, $sent, $imported ) = loop( true, false, 1, true );
$back = (array) ( $imported['reads'][0]['shown'] ?? array() );
check( '  imported, Overwrite Images off: the sold coin\'s pictures downloaded again, behind the new ones', array( count( $imported['reads'][0]['downloads'] ?? array() ), array_slice( $back, 0, 2 ), count( $back ) ), array( 2, $shop['new'] ?? null, 4 ) );
list( $shop, $sent, $imported ) = loop( true, true, 1, true );
check( '  imported, Overwrite Images on: in place of them', array( count( $imported['reads'][0]['downloads'] ?? array() ), count( $imported['reads'][0]['shown'] ?? array() ), array_values( array_intersect( (array) ( $imported['reads'][0]['shown'] ?? array() ), (array) ( $shop['new'] ?? array() ) ) ) ), array( 2, 2, array() ) );

echo "\nEBAY MADE THE RELIST, ITS ANSWER WAS LOST: NOTHING DOWNLOADED, THE PICTURES HELD (FIX ROUNDS 1 AND 2)\n" . str_repeat( '=', 124 ) . "\n";

// The review's P7: the photos are recorded before the call, against the
// listing relisted, already spent (TCGiant_Sync_Own_Photos::
// record_unanswered(), fix round 2, R2-M1). The import follows eBay's relist
// on to its new number (TCGiant_Sync_Inventory::follow_relist(), which moves
// no record since fix round 2) and holds the new listing's pictures:
// nothing downloaded, the product's photos as they are, nothing learnt by
// place. A timeout; the same request sent again, which eBay answers as a
// UUID used before; and (the review's P10, R2-L3) a request sent again whose
// last answer says nothing of a duplicate.
foreach ( array( 'timeout', 'resend', 'resent' ) as $lost ) {
	$how = array( 'timeout' => 'a timeout', 'resend' => 'the request sent again (eBay: UUID used before)', 'resent' => 'the request sent again (eBay: "already relisted")' )[ $lost ];
	foreach ( array( false, true ) as $ow ) {
		list( $shop, $sent, $imported, $code, $recorded, $ended ) = loop( false, $ow, 1, false, false, $lost );
		$said = $how . ( $ow ? ', Overwrite Images on' : ', Overwrite Images off' );
		check( $said . ', from the website: the relist\'s answer an error, the product still on its ended listing, the photos it sent recorded against it', array( $code, count( $sent ), $recorded === $ended ), array( 'timeout' === $lost ? 'http_request_failed' : 'api_error', 2, true ) );
		nothing_came_back( '  the import that follows eBay\'s relist', $shop, $imported );
		check( '  its pictures held, nothing learnt by place, by either read', array_map( function ( $r ) { return array( $r['held'], $r['learnt'], array_values( $r['numbers'] ) ); }, $imported['reads'] ?? array() ), array_fill( 0, 2, array( true, 0, array( array(), array() ) ) ) );
	}
	list( $shop, $sent, $imported, $code ) = loop( false, false, 2, false, false, $lost );
	nothing_came_back( $how . ', from the website, GetItem giving one fewer', $shop, $imported );
	check( '  held, nothing learnt', array( $imported['reads'][0]['held'] ?? null, $imported['reads'][0]['learnt'] ?? null ), array( true, 0 ) );
	list( $shop, $sent, $imported, $code ) = loop( true, false, 1, false, false, $lost );
	nothing_came_back( $how . ', eBay hosting', $shop, $imported );
	check( '  each known by its upload\'s number', array_values( $imported['reads'][0]['numbers'] ?? array() ), array_map( function ( $id ) { return array( 'acoin' . $id ); }, $shop['new'] ?? array() ) );
}
check( 'Inventory::follow_relist() moves no record of photos sent on to a relist (fix round 2)', false === strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-inventory.php' ), 'TCGiant_Sync_Own_Photos::follow' ), true );

echo "\nFIX ROUND 2 (R2-M1): A RECORD WRITTEN BEFORE A RELIST EBAY NEVER MADE TEACHES NOTHING\n" . str_repeat( '=', 124 ) . "\n";

// The review's P9. The relist fails before eBay acts - a connection refused,
// a proxy's page in place of eBay's answer - so the record written before
// the call is kept, against the ended listing. The next import reads that
// listing, still the sold coin's. The product stands in for one made on the
// website and pushed from it with Overwrite Images off, which stores no
// imported addresses (TCGiant_Sync_Image_Localizer::set_external_images()),
// so learn_by_place() has nothing that says the read is not new. A record
// still unread would teach the sold coin's pictures to the new photos by
// place, and the next relist would count them the same and send nothing.
foreach ( array( 'http_request_failed' => 'cURL error 7: Failed to connect to api.ebay.com port 443: Connection refused', 'invalid_xml' => 'Invalid XML from eBay Trading API.' ) as $tcg_code => $tcg_msg ) {
	$shop = own_world( 'relist-shop', array( 'settings' => array(), 'tag' => 'sold', 'old' => 2, 'new' => 2, 'pushed' => false ) );
	list( $pid, $ids ) = shop_here( $shop, false, array() );
	$ended  = (string) get_post_meta( $pid, '_ebay_item_id', true );
	$before = meta_now( $ids );
	$API->answers['RelistFixedPriceItem'] = new WP_Error( $tcg_code, $tcg_msg );
	list( $res, $calls ) = relist_now( $pid, ended_answer( $ended, $shop['listing'] ) );
	unset( $API->answers['RelistFixedPriceItem'] );
	$rec = record_of( $pid );
	check( 'P9, ' . $tcg_code . ': nothing relisted; the photos it sent kept recorded against the ended listing, spent', array( code_of( $res ), count( pictures_in( $calls[1][1] ?? '' ) ), $rec[0] ?? null, ! empty( $rec[4] ) ), array( $tcg_code, 2, $ended, true ) );
	$delta = delta( $before, meta_now( $ids ) );
	$delta[ $pid ]['delete'][] = '_tcgiant_external_image_urls';
	$old_reads = array_map( function ( $url ) { return g_url( TCGiant_Sync_Own_Photos::number( $url ) ); }, $shop['listing'] );
	$imported  = own_world( 'relist-import', array( 'settings' => array(), 'product' => $pid, 'delta' => $delta, 'reads' => array( $old_reads ) ) );
	$r0        = $imported['reads'][0] ?? array();
	check( '  the next import reads the old listing (its pictures the old photos\', known by the addresses they came from): nothing learnt by place, nothing downloaded, the product\'s photos as they are', array( $r0['learnt'] ?? null, array_values( $r0['numbers'] ?? array( 'x' ) ), $r0['downloads'] ?? null, $r0['shown'] ?? null ), array( 0, array( array(), array() ), array(), $shop['new'] ) );
	// What that read wrote, carried back here; Auto-Relist the next day.
	foreach ( (array) ( $r0['numbers'] ?? array() ) as $id => $rows ) {
		foreach ( (array) $rows as $value ) {
			add_post_meta( (int) $id, NUMBER_META, $value );
		}
	}
	list( $res, $calls ) = relist_now( $pid, ended_answer( $ended, $shop['listing'] ), 'Auto-Relist' );
	check( '  the next relist sends the new photos: the listing still shows the sold coin', array( code_of( $res ), pictures_in( $calls[1][1] ?? '' ) ), array( 'relisted', array_map( function ( $id ) { return 'https://shop.example/wp-content/uploads/' . get_post_meta( $id, '_wp_attached_file', true ); }, $shop['new'] ) ) );
}
// The record itself: one written before the call never teaches a place.
coin( 851, array( 8511, 8512 ) );
TCGiant_Sync_Own_Photos::record_unanswered( 851, '110000000851', array( 'ids' => array( 8511, 8512 ), 'urls' => sites( array( 8511, 8512 ) ), 'shown' => array( 8511, 8512 ) ) );
check( 'record_unanswered(): the record learn_by_place() waits for, already spent - it learns nothing', array( ! empty( record_of( 851 )[4] ), TCGiant_Sync_Own_Photos::learn_by_place( 851, array( z_url( num( 'zz', 1 ) ), z_url( num( 'zz', 2 ) ) ) ), numbers( 8511 ), numbers( 8512 ) ), array( true, array(), array(), array() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIMAGE CLEANUP'S WORDS FOR A REUSED PRODUCT, AND THE API BUDGET LINES\n" . str_repeat( '=', 124 ) . "\n";

$view = (string) file_get_contents( dirname( __DIR__ ) . '/admin/views/image-cleanup.php' );
preg_match( '/array\( \$tc_check, __\( \'(.*?)\', \'tcgiant-sync\' \) \)/', $view, $tc_m );
check( 'Image Cleanup: the list of products no longer showing a photo of their own says a reused product can be on it, and to leave it if its photos are right', false !== strpos( $tc_m[1] ?? '', 'A product reused for a new item can be listed here only because an earlier item\\\'s photo is still attached to it: if its photos are right, leave it as it is.' ), true );
check( '  the rest of that list\'s words as they were, around it', array( 0 === strpos( $tc_m[1] ?? '', 'They no longer show a photo of their own that is still attached to them, which may have been their main photo before a new listing went up without it. ' ), substr( $tc_m[1] ?? '', -71 ) ), array( true, 'Check each, and choose its main photo again if it is not the right one:' ) );

$imp_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-importer.php' );
check( 'the full sync\'s and the scan\'s "API budget: N/M": M is get_today_limit(), the limit N is counted against, never the fleet\'s constant', array( substr_count( $imp_src, '$api->get_remaining_today(), $api->get_today_limit()' ), substr_count( $imp_src, "\$api->get_remaining_today(),\n\t\t\t\$api->get_today_limit()" ), substr_count( $imp_src, 'get_remaining_today(), TCGiant_Sync_API::DAILY_CALL_LIMIT' ), substr_count( $imp_src, "get_remaining_today(),\n\t\t\tTCGiant_Sync_API::DAILY_CALL_LIMIT" ) ), array( 1, 1, 0, 0 ) );

done();
