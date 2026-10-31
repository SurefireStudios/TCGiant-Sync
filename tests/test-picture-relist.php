<?php
/**
 * Harness: 3.27.8's photo rules on 3.28.0's push paths.
 *
 * 3.28.0's push of an ended listing asks eBay about it first, and when eBay
 * has relisted it, follows the relist: a live relist is revised, an ended one
 * gets the new listing. 3.27.8's photo rules were written for the push before
 * that, which went straight from the refused revise to a new listing. Here
 * they are checked on the new path:
 * - never a mix of eBay's photos and the website's, in any item;
 * - a revise that would leave a photo out is sent no PictureDetails, so the
 *   live relist keeps its own, and says so once eBay has answered;
 * - no more than 24 photos, said once;
 * - every photo uploaded once a push, whatever is built twice;
 * - the relist's revise says which description it sent (3.27.8's record).
 *
 * And on a staging copy (a child run, TCGIANT_SYNC_IS_STAGING true): no
 * upload is asked for. The photo is decided as one eBay would not take, for
 * the copy's reason, and a copy of the live shop's cached eBay copies is used.
 *
 * Loaded for real: the exporter's item build and do_push(), with eBay played
 * per listing (GetItem, the Revise and Add calls, the picture upload). What
 * 3.28.0's relist-follow needs of the inventory and mapper classes is played.
 * The photos' files are real files in this run's TMP.
 *
 * @package TCGiant_Sync
 */

$STAGING_CHILD = in_array( '--staging', (array) ( $argv ?? array() ), true );
if ( $STAGING_CHILD ) {
	define( 'TCGIANT_SYNC_IS_STAGING', true );
}

require __DIR__ . '/support/template-world.php';

class TCGiant_Sync_Runtime {
	public static function __callStatic( $name, $args ) { return null; }
}
class TCGiant_Sync_Inventory {
	const SETTLE_KEYS = array();
	// eBay's reason for an end, read from the answer in hand (3.28.5): its
	// rules are test-sold-view.php's, Auto-Relist's use test-relist-guard.php's.
	public static function note_ebay_end( $product_id, array $item ) {}
	public static function ended_by_shop( $product_id, $item_id ) { return false; }
	public static function restocked_after_website_sale( $product_id ) { return 0; }
	// Settled once its sales were taken in, as the real class records it.
	public static function listing_already_settled( $product_id, $item_id ) { return '' !== (string) $item_id && (string) $item_id === (string) ( $GLOBALS['SETTLED_ITEM'][ (int) $product_id ] ?? '' ); }
	public static function variations_already_settled( $product_id, $item_id ) { return true; }
	public static function apply_ended_listing_stock( $product_id, $listed, $sold ) {
		$GLOBALS['SETTLED_ITEM'][ (int) $product_id ] = (string) get_post_meta( $product_id, '_ebay_item_id', true );
		return (int) wc_get_product( $product_id )->get_stock_quantity();
	}
	public static function listing_facts( array $item ) { return array( 'status' => 'Active' ); }
	public static function follow_item_number_sku( $product_id, $old, $new ) {}
	public static function follow_relist( $product_id, $old, $new, array $facts, $source ) {
		$GLOBALS['FOLLOWED'][] = array( (int) $product_id, (string) $old, (string) $new );
		update_post_meta( $product_id, '_ebay_item_id', $new );
	}
}
class TCGiant_Sync_Mapper {
	public static function instance() { return new self(); }
	public function goods_sku( $product_id, $item_id ) { return ''; }
	public function map_ebay_to_woo( $item ) { return array( 'sku' => '', 'sku_source' => '', 'identifiers' => array() ); }
	public function other_goods_sku( $product_id, $listing_id, $sku, $source, $ids ) { return ''; }
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
 * eBay: GetItem answered per listing from $items; a Revise of a listing in
 * $ended refused as ended, or with $refuse_revise's error; the picture upload
 * recorded by file name and answered with a copy on eBay unless $refuse names
 * the file.
 */
class Relist_API extends Template_World_API {
	public $uploads       = array();
	public $refuse        = array();
	public $items         = array();
	public $ended         = array();
	public $refuse_revise = array();
	public function upload_picture( $file_path, $name = '' ) {
		$this->uploads[] = basename( $file_path );
		return $this->refuse[ basename( $file_path ) ] ?? eps( (int) preg_replace( '/\D/', '', basename( $file_path ) ) );
	}
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->calls[] = array( $call_name, $xml_body );
		preg_match( '#<ItemID>(\d+)</ItemID>#', (string) $xml_body, $m );
		$id = $m[1] ?? '';
		if ( 'GetItem' === $call_name ) {
			return isset( $this->items[ $id ] ) ? array( 'Ack' => 'Success', 'Item' => $this->items[ $id ] ) : new WP_Error( 'ebay', 'Item not found.' );
		}
		if ( 0 === strpos( $call_name, 'Revise' ) && isset( $this->refuse_revise[ $id ] ) ) {
			return $this->refuse_revise[ $id ];
		}
		if ( 0 === strpos( $call_name, 'Revise' ) && isset( $this->ended[ $id ] ) ) {
			return new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
		}
		return array( 'Ack' => 'Success', 'ItemID' => '' !== $id ? $id : '110000000999' );
	}
}
$GLOBALS['API'] = new Relist_API();
$API            = $GLOBALS['API'];
$tcg_slot       = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_slot->setAccessible( true );
}
$tcg_slot->setValue( null, $API );
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';

$DIR = sys_get_temp_dir() . '/tcg-picture-relist-' . getmypid();
if ( ! is_dir( $DIR ) ) {
	mkdir( $DIR, 0777, true );
}
register_shutdown_function( function () use ( $DIR ) {
	foreach ( (array) glob( $DIR . '/*' ) as $file ) {
		@unlink( $file );
	}
	@rmdir( $DIR );
} );

function site( $id ) { return 'https://shop.example/wp-content/uploads/2026/10/coin-' . $id . '.jpg'; }
function eps( $id ) { return 'https://i.ebayimg.com/images/g/coin' . $id . '/s-l1600.jpg'; }
function kept( $id ) { return 'https://i.ebayimg.com/images/g/kept' . $id . '/s-l1600.jpg'; }
function sites( array $ids ) { return array_map( 'site', $ids ); }
function files( array $ids ) { return array_map( function ( $id ) { return 'coin-' . $id . '.jpg'; }, $ids ); }
/** A product whose photos each have a file here and an address on the website. */
function photographed( $product_id, array $photo_ids ) {
	foreach ( $photo_ids as $id ) {
		$GLOBALS['W']['images'][ $id ] = site( $id );
		$file                          = $GLOBALS['DIR'] . '/coin-' . $id . '.jpg';
		file_put_contents( $file, 'JPEG ' . $id );
		$GLOBALS['W']['files'][ $id ] = $file;
	}
	return new WC_Product( $product_id, array( 'name' => 'Morgan Dollar ' . $product_id, 'description' => 'A Morgan dollar.', 'sku' => 'NMX-' . $product_id, 'image_id' => $photo_ids[0], 'gallery' => array_slice( $photo_ids, 1 ), 'stock' => 2 ) );
}
/** An imported photo kept on eBay: no file here, and its own address is eBay's. */
function kept_on_ebay( $id ) {
	$GLOBALS['W']['images'][ $id ] = kept( $id );
	unset( $GLOBALS['W']['files'][ $id ] );
}
/** A photo whose file an offloader moved: no file here, its address elsewhere. */
function offloaded( $id ) {
	$GLOBALS['W']['images'][ $id ] = 'https://cdn.example/coin-' . $id . '.jpg';
	unset( $GLOBALS['W']['files'][ $id ] );
}
/** A fresh eBay copy of a photo, cached by an earlier push. */
function cached_on_ebay( $id ) {
	update_post_meta( $id, '_tcgiant_eps_url', eps( $id ) );
	update_post_meta( $id, '_tcgiant_eps_key', (string) filemtime( $GLOBALS['W']['files'][ $id ] ) );
	update_post_meta( $id, '_tcgiant_eps_time', time() );
}
function pictures( $xml ) {
	preg_match_all( '#<PictureURL>(.*?)</PictureURL>#', (string) $xml, $m );
	return $m[1];
}
function picture_nodes( $xml ) { return substr_count( (string) $xml, '<PictureDetails' ); }
function warnings_from( $from ) {
	$out = array();
	foreach ( array_slice( $GLOBALS['W']['log'], $from ) as $row ) {
		if ( 'warning' === $row[0] ) {
			$out[] = $row[1];
		}
	}
	return $out;
}
function hosting( $on ) { update_option( 'tcgiant_sync_ebay_settings', array( 'host_images_on_ebay' => $on ? '1' : '0' ) ); }
/** A push (do_push()): each call with its listing, photos and PictureDetails count; the uploads; the warnings; the result. */
function pushed( WC_Product $product ) {
	$GLOBALS['FOR']          = 'Push';
	$GLOBALS['API']->calls   = array();
	$GLOBALS['API']->uploads = array();
	$from                    = count( $GLOBALS['W']['log'] );
	$result                  = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	$calls                   = array();
	foreach ( $GLOBALS['API']->calls as $call ) {
		preg_match( '#<ItemID>(\d+)</ItemID>#', (string) $call[1], $m );
		$calls[] = array( $call[0], $m[1] ?? '', pictures( $call[1] ), picture_nodes( $call[1] ) );
	}
	return array( $calls, $GLOBALS['API']->uploads, warnings_from( $from ), $result );
}
/** The calls' names and listings only. */
function route( array $calls ) {
	return array_map( function ( $c ) { return array( $c[0], $c[1] ); }, $calls );
}
/** The item Verify builds: its photos, the uploads asked for, the warnings. */
function built( WC_Product $product ) {
	$GLOBALS['FOR']          = 'Verify';
	$GLOBALS['API']->uploads = array();
	$from                    = count( $GLOBALS['W']['log'] );
	$xml                     = item_xml( $product, push_settings( $product ) );
	return array( pictures( $xml ), $GLOBALS['API']->uploads, warnings_from( $from ) );
}
/** A product on a listing that has ended, which eBay relisted as $new; the relist live or not. */
function ended_and_relisted( $product_id, $old, $new, $relist_live = true ) {
	update_post_meta( $product_id, '_ebay_item_id', $old );
	update_post_meta( $product_id, '_ebay_listing_type', 'FixedPriceItem' );
	update_post_meta( $product_id, '_ebay_listing_status', 'Active' );
	$GLOBALS['API']->ended[ $old ] = true;
	$GLOBALS['API']->items[ $old ] = array( 'ItemID' => $old, 'Quantity' => '3', 'Site' => 'US', 'ListingDetails' => array( 'RelistedItemID' => $new ), 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ) );
	$GLOBALS['API']->items[ $new ] = array( 'ItemID' => $new, 'Quantity' => '3', 'Site' => 'US', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => $relist_live ? 'Active' : 'Completed' ) );
}
/** The exporter's own wording, from its own methods. */
function unchanged_warning( $product_id, array $held ) {
	return private_method( 'TCGiant_Sync_Exporter', 'photos_unchanged_warning' )->invoke( null, $product_id, $held );
}
function left_out_warning( $product_id, array $left_out ) {
	return private_method( 'TCGiant_Sync_Exporter', 'left_out_warning' )->invoke( null, $product_id, $left_out, $GLOBALS['FOR'] ?? 'Push' );
}
function mixed_warning( $photo, $product, $reason ) {
	return ( $GLOBALS['FOR'] ?? 'Push' ) . sprintf( ': photo #%d of WC #%d could not be put on eBay (%s), so this listing\'s photos are all sent from your website. eBay does not accept a mix.', $photo, $product, $reason );
}

// ─────────────────────────────────────────────────────────────────────────────
// The staging child: report what a copy's builds and push did, as JSON.
// ─────────────────────────────────────────────────────────────────────────────

if ( $STAGING_CHILD ) {
	$out = array( 'staging' => TCGIANT_SYNC_IS_STAGING, 'why' => rtrim( TCGiant_Sync_Environment::message( 'wp-config' ), '. ' ) );

	// A photo kept on eBay, one with a cached eBay copy, one with neither, and
	// one whose file an offloader moved.
	hosting( false );
	$p = photographed( 640, array( 341, 342, 343, 344 ) );
	kept_on_ebay( 341 );
	cached_on_ebay( 342 );
	offloaded( 344 );
	list( $pics, $uploads, $warned ) = built( $p );
	$out['verify_case3'] = array( $pics, $uploads, $warned );

	// The setting on, no photo on eBay.
	hosting( true );
	$p = photographed( 642, array( 345, 346 ) );
	list( $pics, $uploads, $warned ) = built( $p );
	$out['verify_case2'] = array( $pics, $uploads, $warned );

	// A push of a live listing, a photo kept on eBay and one of the shop's.
	hosting( false );
	$p = photographed( 644, array( 347, 348 ) );
	kept_on_ebay( 347 );
	update_post_meta( 644, '_ebay_item_id', '110000000644' );
	update_post_meta( 644, '_ebay_listing_status', 'Active' );
	list( $calls, $uploads, $warned ) = pushed( $p );
	$out['push_case3'] = array( route( $calls ), $calls[0][3] ?? null, $uploads, $warned );

	echo json_encode( $out );
	$GLOBALS['harness_done'] = true;
	exit( 0 );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PUSH OF AN ENDED LISTING EBAY RELISTED, THE RELIST LIVE: IT IS REVISED (3.28.0)\n" . str_repeat( '=', 124 ) . "\n";

// A photo kept on eBay, the setting off: the shop's own go as eBay copies.
hosting( false );
$p = photographed( 620, array( 291, 292, 293 ) );
kept_on_ebay( 291 );
ended_and_relisted( 620, '110000000620', '110000000621' );
list( $calls, $uploads, $warned, $result ) = pushed( $p );
$all = array( kept( 291 ), eps( 292 ), eps( 293 ) );
check( 'kept on eBay, every upload works: the revise refused as ended, eBay asked twice, the relist revised', route( $calls ), array( array( 'ReviseFixedPriceItem', '110000000620' ), array( 'GetItem', '110000000620' ), array( 'GetItem', '110000000621' ), array( 'ReviseFixedPriceItem', '110000000621' ) ) );
check( '  both revises all on eBay', array( $calls[0][2], $calls[3][2] ), array( $all, $all ) );
check( '  each of the shop\'s photos uploaded once, nothing said', array( $uploads, $warned ), array( files( array( 292, 293 ) ), array() ) );
check( '  an update of the relist, the product moved on to it', array( is_array( $result ) ? $result['item_id'] : $result->get_error_message(), $result['action'] ?? null, $GLOBALS['FOLLOWED'] ?? null ), array( '110000000621', 'updated', array( array( 620, '110000000620', '110000000621' ) ) ) );
check( '  with the description it sent, for the product\'s record of it (3.27.8)', array( isset( $result['desc']['hash'] ), $result['desc']['hash'] ?? '' ), array( true, sha1( $result['desc']['html'] ?? 'none' ) ) );

// One of the shop's refused: the relist keeps its own photos too.
$p = photographed( 622, array( 294, 295, 296 ) );
kept_on_ebay( 294 );
ended_and_relisted( 622, '110000000622', '110000000623' );
$API->refuse['coin-296.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned, $result ) = pushed( $p );
check( 'one of the shop\'s refused: neither revise is sent any PictureDetails, the relist\'s revise goes', array( route( $calls ), $calls[0][3], $calls[3][3], is_array( $result ) ? $result['item_id'] : $result->get_error_message() ), array( array( array( 'ReviseFixedPriceItem', '110000000622' ), array( 'GetItem', '110000000622' ), array( 'GetItem', '110000000623' ), array( 'ReviseFixedPriceItem', '110000000623' ) ), 0, 0, '110000000623' ) );
check( '  each upload tried once, and one warning, once eBay has answered: the listing\'s photos were not changed', array( $uploads, $warned ), array( files( array( 295, 296 ) ), array( unchanged_warning( 622, array( 296 => 'eBay refused the file' ) ) ) ) );
unset( $API->refuse['coin-296.jpg'] );

// The relist's revise refused for another reason: said all the same, and the
// push fails with eBay's error.
$p = photographed( 624, array( 297, 298 ) );
kept_on_ebay( 297 );
offloaded( 298 );
ended_and_relisted( 624, '110000000624', '110000000625' );
$API->refuse_revise['110000000625'] = new WP_Error( 'api_error', 'Item specifics are missing.' );
list( $calls, $uploads, $warned, $result ) = pushed( $p );
check( 'the relist\'s revise refused (not ended): one warning, the photos not changed, and the push fails', array( $warned, is_wp_error( $result ) ? $result->get_error_message() : 'pushed', $calls[3][3] ?? null ), array( array( unchanged_warning( 624, array( 298 => 'its file is not on this server' ) ) ), 'Item specifics are missing.', 0 ) );

// The relist itself turns out to have ended (eBay's race): nothing listed, and
// no photo line, as when the first listing is not listed again.
$p = photographed( 626, array( 299, 300 ) );
kept_on_ebay( 299 );
offloaded( 300 );
ended_and_relisted( 626, '110000000626', '110000000627' );
$API->refuse_revise['110000000627'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
list( $calls, $uploads, $warned, $result ) = pushed( $p );
check( 'the relist\'s revise refused as ended: the push fails, no photo line', array( is_wp_error( $result ), $warned, count( $calls ) ), array( true, array(), 4 ) );

// None on eBay, the setting on, one refused: all from the website, in both.
hosting( true );
$p = photographed( 628, array( 301, 302, 303 ) );
ended_and_relisted( 628, '110000000628', '110000000629' );
$API->refuse['coin-302.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned ) = pushed( $p );
check( 'none on eBay, the setting on, one refused: both revises all from the website', array( $calls[0][2], $calls[3][2] ), array( sites( array( 301, 302, 303 ) ), sites( array( 301, 302, 303 ) ) ) );
check( '  the uploads stop at the refusal and are not tried again, said once', array( $uploads, $warned ), array( files( array( 301, 302 ) ), array( mixed_warning( 302, 628, 'eBay refused the file' ) ) ) );
unset( $API->refuse['coin-302.jpg'] );

// None on eBay, the setting off: 3.27.7's addresses, nothing uploaded.
hosting( false );
$p = photographed( 630, array( 304, 305 ) );
ended_and_relisted( 630, '110000000630', '110000000631' );
list( $calls, $uploads, $warned ) = pushed( $p );
check( 'none on eBay, the setting off: the website\'s addresses in both, nothing uploaded or said', array( $calls[0][2], $calls[3][2], $uploads, $warned ), array( sites( array( 304, 305 ) ), sites( array( 304, 305 ) ), array(), array() ) );

// Thirty photos: the first 24 in both revises, said once.
$p = photographed( 632, range( 401, 430 ) );
ended_and_relisted( 632, '110000000632', '110000000633' );
list( $calls, $uploads, $warned ) = pushed( $p );
check( 'thirty photos: both revises the first 24, said once', array( $calls[0][2], $calls[3][2], $warned ), array( sites( range( 401, 424 ) ), sites( range( 401, 424 ) ), array( 'Push: WC #632 has more photos than the 24 eBay takes in one listing, so the 6 after the first 24 were not sent.' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE RELIST HAS ENDED TOO: THE NEW LISTING HAS THE PHOTOS, BUT FOR THOSE EBAY CANNOT TAKE\n" . str_repeat( '=', 124 ) . "\n";

$p = photographed( 634, array( 306, 307, 308 ) );
kept_on_ebay( 306 );
ended_and_relisted( 634, '110000000634', '110000000635', false );
$API->refuse['coin-308.jpg'] = new WP_Error( 'upload_failed', 'eBay refused the file.' );
list( $calls, $uploads, $warned, $result ) = pushed( $p );
check( 'the relist ended unsold: the first revise sent no PictureDetails, then a new listing', array( route( $calls ), $calls[0][3] ), array( array( array( 'ReviseFixedPriceItem', '110000000634' ), array( 'GetItem', '110000000634' ), array( 'GetItem', '110000000635' ), array( 'AddFixedPriceItem', '' ) ), 0 ) );
check( '  the new listing all on eBay, less the one, with the left-out warning once', array( $calls[3][2], $uploads, $warned, $result['action'] ?? null ), array( array( kept( 306 ), eps( 307 ) ), files( array( 307, 308 ) ), array( left_out_warning( 634, array( 308 => 'eBay refused the file' ) ) ), 'relisted' ) );
unset( $API->refuse['coin-308.jpg'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nON A STAGING COPY NO UPLOAD IS ASKED FOR (3.28.0)\n" . str_repeat( '=', 124 ) . "\n";

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --staging 2>&1', $tcg_lines, $tcg_code );
$tcg_child = json_decode( (string) end( $tcg_lines ), true );
check( 'the child is a staging copy, by wp-config', array( $tcg_code, $tcg_child['staging'] ?? null ), array( 0, true ) );
$why = (string) ( $tcg_child['why'] ?? '' );
// The child's builds are Verify's, and say so (3.28.5).
$GLOBALS['FOR'] = 'Verify';
list( $pics, $uploads, $warned ) = $tcg_child['verify_case3'] ?? array( null, null, null );
check( 'Verify of a product with a photo kept on eBay: nothing uploaded', $uploads, array() );
check( '  the kept photo and the cached eBay copy go, the other is left out for the copy\'s reason, the offloaded one as not here', array( $pics, $warned ), array( array( kept( 341 ), eps( 342 ) ), array( left_out_warning( 640, array( 343 => $why, 344 => 'its file is not on this server' ) ) ) ) );
list( $pics, $uploads, $warned ) = $tcg_child['verify_case2'] ?? array( null, null, null );
check( 'the setting on, none on eBay: nothing uploaded, the website\'s addresses, said once for the copy\'s reason', array( $uploads, $pics, $warned ), array( array(), sites( array( 345, 346 ) ), array( mixed_warning( 345, 642, $why ) ) ) );
list( $route, $nodes, $uploads, $warned ) = $tcg_child['push_case3'] ?? array( null, null, null, null );
$GLOBALS['FOR'] = 'Push';
check( 'a push of a live listing: nothing uploaded, its revise sent no PictureDetails, said once', array( $route, $nodes, $uploads, $warned ), array( array( array( 'ReviseFixedPriceItem', '110000000644' ) ), 0, array(), array( unchanged_warning( 644, array( 348 => $why ) ) ) ) );

done();
