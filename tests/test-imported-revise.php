<?php
/**
 * Harness: an Update of an imported listing leaves eBay's own fields alone
 * (3.28.8, item 13).
 *
 * Until 3.28.8 a push sent the same body to an Add and a Revise, and the
 * shop's defaults filled every field the product did not set. So an Update
 * of a listing first made on eBay rewrote eBay's own values: a used caliper's
 * condition 3000 became 1000, a PSA 10 slab became an ungraded card in the
 * shop's default category, and the listing's business policies, location and
 * handling time were reset to the shop's. An auction was sent the shop's
 * Fixed Price format, Good 'Til Cancelled, and a quantity.
 *
 * Now the import records eBay's own values for each listing (_ebay_live) and
 * who made it (_ebay_origin). A product the plugin pushed is sent what it
 * always was, byte for byte (decision 15). One imported keeps eBay's values:
 * its revise sends only what the product overrides, and a new listing in its
 * place carries eBay's values ahead of the shop's. A live auction's revise
 * never sends its price, quantity or format, and an ended imported auction is
 * relisted as itself (decision 16). The shop's default card condition goes as
 * its pair where eBay does not list it (decision 17).
 *
 * The listings are imported for real (support/imported-revise-import.php:
 * TCGiant_Sync_Mapper, TCGiant_Sync_Ebay_Live, from eBay's XML turned into
 * arrays as the API class turns them), and the products they make are pushed
 * here through the real TCGiant_Sync_Exporter, with eBay played at
 * TCGiant_Sync_API::trading_api_request(): each item checked is the request
 * body as it would go out, read as XML. The pushed products' bodies are held
 * to 3.28.6's (support/imported-revise-pushed.php, against
 * support/imported-revise-3286.json). The eBay tab and its Push button run in
 * a process of their own (support/imported-revise-admin.php).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// What a push needs of the plugin's other classes, played.
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
	/** eBay's sales of an ended listing taken off the stock, lowering only. */
	public static function apply_ended_listing_stock( $product_id, $listed, $sold, $item = null ) {
		$product = wc_get_product( $product_id );
		$left    = max( 0, (int) $listed - (int) $sold );
		if ( $left < (int) $product->get_stock_quantity() ) {
			$product->data['stock'] = $left;
		}
		return (int) $product->get_stock_quantity();
	}
}
class TCGiant_Sync_DB {
	public static function upsert( $row ) { return 1; }
	public static function table_exists() { return false; }
	public static function get_by_product( $id ) { return null; }
}
function get_post_status( $id ) { return $GLOBALS['W']['status'][ (int) $id ] ?? 'publish'; }
function get_post_field( $field, $id ) { return $GLOBALS['W']['fields'][ (int) $id ][ $field ] ?? ''; }
// No photo's file is on this server: none can be put on eBay.
function get_attached_file( $id ) { return ''; }

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-listing-link.php';

$API = $GLOBALS['API'];

/** A card shop's defaults: 183050, ungraded Near Mint, Austin, its own policies. */
function card_shop() {
	TCGiant_Sync_OAuth::$settings = array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => '183050',
		'export_condition_id'       => '1000',
		'export_condition_type'     => 'ungraded',
		'export_ungraded_condition' => '400010',
		'export_location'           => 'Austin, TX',
		'export_postal_code'        => '78701',
		'export_fulfillment_policy' => '250000011',
		'export_return_policy'      => '250000012',
		'export_payment_policy'     => '250000013',
		'export_listing_type'       => 'FixedPriceItem',
		'export_listing_duration'   => 'GTC',
	);
}
card_shop();

// The import half, for real, in its own process.
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/imported-revise-import.php' ) . ' 2>&1', $tcg_out );
$IMPORT = json_decode( (string) end( $tcg_out ), true );

/**
 * A product the import made, here: the meta it saved, a price and stock, and
 * photos the image localiser made from the listing's (each marked with the
 * address it came from) or the shop's own.
 */
function imported( $name, $id, array $data = array(), array $extra = array() ) {
	$saved = $GLOBALS['IMPORT']['imported'][ $name ]['meta'];
	unset( $GLOBALS['W']['meta'][ $id ] );
	$product = new WC_Product( $id, array_merge( array( 'name' => (string) $GLOBALS['IMPORT']['answers'][ $name ]['Title'], 'description' => 'From eBay.', 'regular_price' => '40.00', 'stock' => 1, 'sku' => (string) ( $saved['_sku'] ?? '' ) ), $data ) );
	foreach ( $extra + $saved as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** Photos the localiser made from these eBay addresses: attachment IDs. */
function photos_from( $first_id, array $urls ) {
	$ids = array();
	foreach ( array_values( $urls ) as $i => $url ) {
		$id                                = $first_id + $i;
		$GLOBALS['W']['images'][ $id ]     = 'https://shop.example/wp-content/uploads/photo-' . $id . '.jpg';
		$GLOBALS['W']['meta'][ $id ]       = array( '_tcgiant_source_url' => preg_replace( '/\?.*$/', '', $url ) );
		$ids[]                             = $id;
	}
	return $ids;
}
/** The shop's own photo. */
function own_photo( $id ) {
	$GLOBALS['W']['images'][ $id ] = 'https://shop.example/wp-content/uploads/own-' . $id . '.jpg';
	return $id;
}
/** Forget the hour a failed read holds a listing back for (3.28.8), between cases on one listing. */
function forget_failed_reads() {
	foreach ( array_keys( $GLOBALS['W']['transients'] ) as $name ) {
		if ( 0 === strpos( $name, 'tcgiant_sync_live_read_failed_' ) ) {
			unset( $GLOBALS['W']['transients'][ $name ] );
		}
	}
}
/** A push (do_push()): what it answered, and every call it made. */
function push( WC_Product $product ) {
	$GLOBALS['API']->calls = array();
	$from                  = count( $GLOBALS['W']['log'] );
	$res                   = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $product );
	return array( $res, $GLOBALS['API']->calls, warnings_since( $from ) );
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
/** A request body as eBay would read it, or null when it is not well-formed XML. */
function as_xml( $body ) {
	$doc = new DOMDocument();
	if ( ! @$doc->loadXML( '<Request xmlns="urn:ebay:apis:eBLBaseComponents">' . $body . '</Request>' ) ) {
		return null;
	}
	$xp = new DOMXPath( $doc );
	$xp->registerNamespace( 'e', 'urn:ebay:apis:eBLBaseComponents' );
	return $xp;
}
/** The fields an item carries, in order. */
function fields_of( $body ) {
	$xp = as_xml( $body );
	if ( ! $xp ) {
		return null;
	}
	$out = array();
	foreach ( $xp->query( '/e:Request/e:Item/*' ) as $node ) {
		$out[] = $node->localName;
	}
	return $out;
}
function value_of( $body, $path ) {
	$xp = as_xml( $body );
	return $xp ? $xp->evaluate( 'string(/e:Request/e:Item/' . $path . ')' ) : null;
}
/** The ConditionDescriptors an item carries: Name => its Value or AdditionalInfo. */
function descriptors_of( $body ) {
	$xp  = as_xml( $body );
	$out = array();
	foreach ( $xp ? $xp->query( '/e:Request/e:Item/e:ConditionDescriptors/e:ConditionDescriptor' ) : array() as $d ) {
		$value = $xp->evaluate( 'string(e:Value)', $d );
		$out[ $xp->evaluate( 'string(e:Name)', $d ) ] = '' !== $value ? $value : $xp->evaluate( 'string(e:AdditionalInfo)', $d );
	}
	return $out;
}
/** The SellerProfiles an item carries: shipping, return, payment. */
function policies_of( $body ) {
	return array( value_of( $body, 'e:SellerProfiles/e:SellerShippingProfile/e:ShippingProfileID' ), value_of( $body, 'e:SellerProfiles/e:SellerReturnProfile/e:ReturnProfileID' ), value_of( $body, 'e:SellerProfiles/e:SellerPaymentProfile/e:PaymentProfileID' ) );
}
function count_of( $body, $path ) {
	$xp = as_xml( $body );
	return $xp ? $xp->query( '/e:Request/e:Item/' . $path )->length : null;
}
function names_of( array $calls ) {
	return array_column( $calls, 0 );
}
/** The photos a push decided for a product (get_product_image_urls()), or null when none were. */
function decided_photos( $id ) {
	$memo = new ReflectionProperty( 'TCGiant_Sync_Exporter', 'pictures_for' );
	if ( PHP_VERSION_ID < 80100 ) {
		$memo->setAccessible( true );
	}
	return $memo->getValue( exporter() )[ $id ] ?? null;
}
/** eBay's own fields: what a revise of an imported listing leaves out. */
const EBAYS_OWN = array( 'PrimaryCategory', 'CategoryMappingAllowed', 'ConditionID', 'ConditionDescriptors', 'SellerProfiles', 'Location', 'PostalCode', 'DispatchTimeMax', 'ListingType', 'ListingDuration', 'PictureDetails', 'BestOfferDetails', 'ProductSafety' );
function ebays_own_in( $body ) {
	return array_values( array_intersect( (array) fields_of( $body ), EBAYS_OWN ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIMPORT: EBAY'S OWN VALUES AND WHO MADE THE LISTING ARE RECORDED\n" . str_repeat( '=', 124 ) . "\n";

check( 'the import ran, for real, in its own process', is_array( $IMPORT ) && isset( $IMPORT['imported']['card'] ), true );
$card_live = $IMPORT['imported']['card']['meta']['_ebay_live'] ?? array();
check( 'IMPORT 1. the PSA 10 card: its item, condition and descriptors', array( $card_live['item_id'] ?? null, $card_live['condition_id'] ?? null, $card_live['descriptors'] ?? null ), array( '256100000001', '2750', array( array( 'name' => '27501', 'value' => '275010' ), array( 'name' => '27502', 'value' => '275020' ), array( 'name' => '27503', 'info' => '81234567' ) ) ) );
check( '  its category with eBay\'s full path, its three policies', array( $card_live['category_id'] ?? null, $card_live['category_name'] ?? null, $card_live['policies'] ?? null ), array( '183454', 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards', array( 'shipping' => '240000011', 'return' => '240000012', 'payment' => '240000013' ) ) );
check( '  Torrance, 90501, US, handling time 1, and its photos without their query', array( $card_live['location'] ?? null, $card_live['postal_code'] ?? null, $card_live['country'] ?? null, $card_live['dispatch_time'] ?? null, $card_live['pictures'] ?? null ), array( 'Torrance, CA', '90501', 'US', '1', array( 'https://i.ebayimg.com/images/g/AAA/s-l1600.jpg', 'https://i.ebayimg.com/images/g/BBB/s-l1600.jpg' ) ) );
check( '  and it is imported, the import saying it wrote that', array( $IMPORT['imported']['card']['meta']['_ebay_origin'] ?? null, $IMPORT['imported']['card']['meta']['_ebay_origin_by'] ?? null ), array( 'import', 'import' ) );
check( '  the caliper: 3000 with its description, 33564, its policies, Columbus', array( $IMPORT['imported']['part']['meta']['_ebay_live']['condition_id'] ?? null, $IMPORT['imported']['part']['meta']['_ebay_live']['condition_description'] ?? null, $IMPORT['imported']['part']['meta']['_ebay_live']['category_id'] ?? null, $IMPORT['imported']['part']['meta']['_ebay_live']['location'] ?? null ), array( '3000', 'Light wear on the bracket.', '33564', 'Columbus, OH' ) );
check( '  the coin: grader 15, letter 18, number 55, its certification number', $IMPORT['imported']['coin']['meta']['_ebay_live']['descriptors'] ?? null, array( array( 'name' => '1', 'value' => '15' ), array( 'name' => '3', 'value' => '18' ), array( 'name' => '4', 'value' => '55' ), array( 'name' => '5', 'info' => '6123456-001' ) ) );
check( 'IMPORT 2. read again without ConditionID, descriptors or SellerProfiles: those kept, the new location taken', array( $IMPORT['reread']['condition_id'] ?? null, count( $IMPORT['reread']['descriptors'] ?? array() ), $IMPORT['reread']['policies']['shipping'] ?? null, $IMPORT['reread']['location'] ?? null ), array( '2750', 3, '240000011', 'Gardena, CA' ) );
check( 'IMPORT 3. a product of the shop\'s linked by its SKU to a listing made on eBay: imported, as one linked by hand is; the listing\'s values recorded', array( $IMPORT['by_sku']['id'], $IMPORT['by_sku']['origin'], $IMPORT['by_sku']['item'], $IMPORT['by_sku']['live_item'] ), array( 700, 'import', '256100000009', '256100000009' ) );
check( 'FIX D. a pushed product re-linked by its SKU, its listing ended, to the one the seller put up on eBay: imported, not its old listing\'s "push"', array( $IMPORT['relinked'][703]['item'] ?? null, $IMPORT['relinked'][703]['origin'] ?? null ), array( '256100000603', 'import' ) );
check( '  re-linked to a listing carrying its own push mark: still the shop\'s', array( '' !== ( $IMPORT['relinked'][704]['mark'] ?? '' ), $IMPORT['relinked'][704]['item'] ?? null, $IMPORT['relinked'][704]['origin'] ?? null ), array( true, '256100000604', 'push' ) );
check( '  a product moved on to a relist: the relist\'s read replaces the old listing\'s values, nothing of them kept', array( $IMPORT['replaced']['item_id'] ?? null, $IMPORT['replaced']['condition_id'] ?? null, $IMPORT['replaced']['location'] ?? null, $IMPORT['replaced']['policies']['shipping'] ?? null ), array( '256100000077', '3000', 'Columbus, OH', '240000021' ) );
check( 'the light path\'s record: the same signature with or without eBay\'s own values in the answer', $IMPORT['sig'][0] === $IMPORT['sig'][1] && '' !== $IMPORT['sig'][0], true );
check( '  the import settings\' fingerprint as before, and the record made with it', array( $IMPORT['fp'][0] === $IMPORT['fp'][1], array_keys( $IMPORT['record'] ?? array() ) ), array( true, array( 'v', 'item', 'sig', 'fp', 'end', 'read', 'light' ) ) );
check( '  a row read in brief, for a product with no record yet: no record with nothing in it', $IMPORT['brief_live'] ?? null, false );
check( 'ORIGIN 28. a duplicate or an unlink drops eBay\'s values, the origin and the shop\'s choice', array_values( array_intersect( array( '_ebay_live', '_ebay_origin', '_ebay_export_follow_defaults' ), $IMPORT['keys'] ?? array() ) ), array( '_ebay_live', '_ebay_origin', '_ebay_export_follow_defaults' ) );

// The importer's helpers, on their own.
$live_one = TCGiant_Sync_Ebay_Live::from_item( array( 'ItemID' => '5', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( 'Name' => '40001', 'Value' => '400011' ) ) ) );
check( 'one descriptor alone, as eBay\'s XML gives it, is a list of one', $live_one['descriptors'], array( array( 'name' => '40001', 'value' => '400011' ) ) );
check( '  an answer with no descriptors container says nothing of them', array_key_exists( 'descriptors', TCGiant_Sync_Ebay_Live::from_item( array( 'ItemID' => '5', 'ConditionID' => '4000' ) ) ), false );
check( '  nor of what is not an answer', TCGiant_Sync_Ebay_Live::from_item( 'x' ), array() );
check( 'merge: another listing\'s record replaced; within one, a missing field kept', array( TCGiant_Sync_Ebay_Live::merge( array( 'item_id' => '1', 'location' => 'A' ), array( 'condition_id' => '3000' ), '2' ), TCGiant_Sync_Ebay_Live::merge( array( 'item_id' => '2', 'location' => 'A' ), array( 'condition_id' => '3000' ), '2' ) ), array( array( 'condition_id' => '3000', 'item_id' => '2' ), array( 'item_id' => '2', 'location' => 'A', 'condition_id' => '3000' ) ) );
update_post_meta( 600, '_ebay_item_id', '9' );
update_post_meta( 600, '_ebay_live', array( 'item_id' => '8', 'location' => 'A' ) );
check( 'snapshot: none for a record of a listing the product no longer follows', TCGiant_Sync_Ebay_Live::snapshot( 600 ), array() );
check( 'record: an answer about another listing is not recorded', array( TCGiant_Sync_Ebay_Live::record( 600, array( 'ItemID' => '7', 'Location' => 'B' ) ), get_post_meta( 600, '_ebay_live', true ) ), array( false, array( 'item_id' => '8', 'location' => 'A' ) ) );
check( '  nor one that says nothing but the item number', TCGiant_Sync_Ebay_Live::record( 600, array( 'ItemID' => '9' ) ), false );
check( '  one about its listing is, and only when it changes something', array( TCGiant_Sync_Ebay_Live::record( 600, array( 'ItemID' => '9', 'Location' => 'B' ) ), TCGiant_Sync_Ebay_Live::record( 600, array( 'ItemID' => '9', 'Location' => 'B' ) ), get_post_meta( 600, '_ebay_live', true ) ), array( true, false, array( 'item_id' => '9', 'location' => 'B' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREVISE: AN IMPORTED LISTING IS SENT ONLY WHAT THE PRODUCT CHANGES\n" . str_repeat( '=', 124 ) . "\n";

$card_photos = photos_from( 9101, $card_live['pictures'] );
$card        = imported( 'card', 101, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ) );
list( $res, $calls ) = push( $card );
$body = $calls[0][1] ?? '';
check( 'REVISE 5. the PSA 10 card under a card shop\'s defaults: ReviseFixedPriceItem, one call', array( names_of( $calls ), $res['action'] ?? null ), array( array( 'ReviseFixedPriceItem' ), 'updated' ) );
check( '  well-formed XML', null !== as_xml( $body ), true );
check( '  none of eBay\'s own fields: no category, condition, policies, location, handling time, format or photos', ebays_own_in( $body ), array() );
check( '  what the product holds, and the listing\'s country', fields_of( $body ), array( 'ItemID', 'Title', 'Description', 'StartPrice', 'Quantity', 'SKU', 'Country', 'Currency', 'Site' ) );
check( '  the country eBay has for it', value_of( $body, 'e:Country' ), 'US' );
$ca_live            = $card_live;
$ca_live['country'] = 'CA';
$p = imported( 'card', 108, array( 'image_id' => own_photo( 9308 ) ), array( '_ebay_live' => $ca_live ) );
list( , $calls ) = push( $p );
check( '  a listing eBay has in Canada: Country CA, not the marketplace\'s US', value_of( $calls[0][1] ?? '', 'e:Country' ), 'CA' );
check( 'PHOTOS. its photos are still the listing\'s, each in its place: none decided, none uploaded (get_product_image_urls() not asked)', decided_photos( 101 ), null );
$bo_settings                       = exporter()->get_export_settings( 101 );
$bo_settings['best_offer_enabled'] = true;
$build                             = private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' );
check( 'the shop\'s Best Offer default (should it reach the settings) is not sent to an imported listing\'s revise, as it would be to a pushed one', array( false !== strpos( $build->invoke( exporter(), $card, $bo_settings, true ), '<BestOfferDetails>' ), false !== strpos( $build->invoke( exporter(), $card, array( '_send_all' => true ) + $bo_settings, true ), '<BestOfferDetails>' ) ), array( false, true ) );
update_post_meta( 101, '_ebay_export_follow_defaults', '1' );
list( , $calls ) = push( $card );
$all  = $calls[0][1] ?? '';
check( 'the same card set to send the shop\'s defaults: every one of those fields, as 3.27 sent them all', array( value_of( $all, 'e:PrimaryCategory/e:CategoryID' ), value_of( $all, 'e:ConditionID' ), descriptors_of( $all ), policies_of( $all ), value_of( $all, 'e:Location' ), value_of( $all, 'e:PostalCode' ), value_of( $all, 'e:DispatchTimeMax' ), value_of( $all, 'e:ListingType' ), value_of( $all, 'e:ListingDuration' ) ), array( '183050', '4000', array( '40001' => '400010' ), array( '250000011', '250000012', '250000013' ), 'Austin, TX', '78701', '3', 'FixedPriceItem', 'GTC' ) );
check( '  and its photos, decided', array( value_of( $all, 'e:PictureDetails/e:PictureURL[1]' ), is_array( decided_photos( 101 ) ) ), array( 'https://shop.example/wp-content/uploads/photo-9101.jpg', true ) );
delete_post_meta( 101, '_ebay_export_follow_defaults' );

// The shop adds a photo of its own: the photos have changed, and are decided
// as ever, all from the website with "Host listing images on eBay" off.
$card->data['gallery'] = array( $card_photos[1], own_photo( 9150 ) );
list( , $calls ) = push( $card );
$body = $calls[0][1] ?? '';
check( 'PHOTOS. a photo added: the photos are decided and sent, all from the website (no mix)', array( count_of( $body, 'e:PictureDetails/e:PictureURL' ), value_of( $body, 'e:PictureDetails/e:PictureURL[3]' ), is_array( decided_photos( 101 ) ) ), array( 3, 'https://shop.example/wp-content/uploads/own-9150.jpg', true ) );
check( '  and nothing else of eBay\'s', ebays_own_in( $body ), array( 'PictureDetails' ) );
$card->data['gallery'] = array( $card_photos[1] );

// The listing's own photos kept on eBay ("Keep eBay-hosted URLs"), and one of
// the shop's own that cannot be put on eBay: the photos have changed, and the
// never-mix rule decides - this revise is sent none, and says so.
$GLOBALS['W']['images'][ $card_photos[0] ] = 'https://i.ebayimg.com/images/g/AAA/s-l1600.jpg';
$GLOBALS['W']['images'][ $card_photos[1] ] = 'https://i.ebayimg.com/images/g/BBB/s-l1600.jpg';
$card->data['gallery']                    = array( $card_photos[1], own_photo( 9151 ) );
list( , $calls, $warned ) = push( $card );
$body = $calls[0][1] ?? '';
check( 'PHOTOS. eBay-hosted photos and one that cannot go to eBay: no PictureDetails, as 3.27.8 decides, said once eBay has answered', array( ebays_own_in( $body ), isset( decided_photos( 101 )['left_out'][9151] ), count( array_filter( $warned, function ( $w ) { return 0 === strpos( $w, 'Push: photo #9151 of WC #101 could not be put on eBay' ); } ) ) ), array( array(), true, 1 ) );
$card->data['gallery'] = array( $card_photos[1] );
list( , $calls, $warned ) = push( $card );
check( '  the same photos as the listing\'s, all on eBay: unchanged, none decided, nothing said', array( ebays_own_in( $calls[0][1] ?? '' ), decided_photos( 101 ), $warned ), array( array(), null, array() ) );
$GLOBALS['W']['images'][ $card_photos[0] ] = 'https://shop.example/wp-content/uploads/photo-9101.jpg';
$GLOBALS['W']['images'][ $card_photos[1] ] = 'https://shop.example/wp-content/uploads/photo-9102.jpg';
check( 'photos_match: the same count, each one\'s source in its place', array(
	TCGiant_Sync_Ebay_Live::photos_match( $card_photos, $card_live ),
	TCGiant_Sync_Ebay_Live::photos_match( array_reverse( $card_photos ), $card_live ),
	TCGiant_Sync_Ebay_Live::photos_match( array( $card_photos[0] ), $card_live ),
	TCGiant_Sync_Ebay_Live::photos_match( $card_photos, array() ),
	TCGiant_Sync_Ebay_Live::photos_match( array( $card_photos[0], 9150 ), $card_live ),
), array( true, false, false, false, false ) );

$part_photos = photos_from( 9201, $IMPORT['imported']['part']['meta']['_ebay_live']['pictures'] );
$part        = imported( 'part', 102, array( 'image_id' => $part_photos[0], 'stock' => 2, 'regular_price' => '120.00' ) );
list( , $calls ) = push( $part );
$body = $calls[0][1] ?? '';
check( 'REVISE 6. the used caliper is revised with no ConditionID: 3.27 sent 1000', array( names_of( $calls ), ebays_own_in( $body ), value_of( $body, 'e:Quantity' ) ), array( array( 'ReviseFixedPriceItem' ), array(), '2' ) );

// A product imported before 3.28.8: no record of eBay's values, no origin, never pushed.
TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_US' );
$legacy                       = new WC_Product( 103, array( 'name' => 'Old import', 'description' => 'x', 'regular_price' => '9.00', 'stock' => 1, 'image_id' => own_photo( 9301 ) ) );
update_post_meta( 103, '_ebay_item_id', '256100000103' );
update_post_meta( 103, '_ebay_listing_type', 'FixedPriceItem' );
update_post_meta( 103, '_ebay_listing_status', 'Active' );
list( $res, $calls ) = push( $legacy );
check( 'REVISE 7. an import from before 3.28.8 in a shop with no export defaults saved: pushed, with the same omissions (3.27 refused it)', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), ebays_own_in( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem' ), array( 'PictureDetails' ) ) );
check( '  and no Country, which no read has recorded: eBay keeps its own, as for every field left out', in_array( 'Country', (array) fields_of( $calls[0][1] ?? '' ), true ), false );
update_post_meta( 103, '_ebay_export_follow_defaults', '1' );
list( $res, $calls ) = push( $legacy );
check( '  set to send the shop\'s defaults, of which there are none: refused before eBay is asked, as 3.27 refused it', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', $calls ), array( 'missing_export_settings', array() ) );
delete_post_meta( 103, '_ebay_export_follow_defaults' );
// eBay's card with no grade recorded, and an auction with no duration
// recorded: neither is sent, so neither is checked.
$no_grade                = $IMPORT['imported']['card']['meta'];
$no_grade['_ebay_live']['descriptors'] = array( array( 'name' => '27501', 'value' => '275010' ) );
$p = imported( 'card', 106, array( 'image_id' => own_photo( 9306 ) ), array( '_ebay_live' => $no_grade['_ebay_live'] ) );
list( $res ) = push( $p );
check( '  a graded card eBay gave no grade for: its condition is not sent, so not checked', is_array( $res ) ? $res['action'] : $res->get_error_message(), 'updated' );
$p = imported( 'auction', 107, array( 'image_id' => own_photo( 9307 ) ), array( '_ebay_listing_duration' => '' ) );
list( $res, $calls ) = push( $p );
check( '  an auction with no duration recorded: its format is not sent, so not checked', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ) ), array( 'updated', array( 'ReviseItem' ) ) );
card_shop();

$auction = imported( 'auction', 104, array( 'image_id' => own_photo( 9401 ), 'regular_price' => '37.00' ), array( '_tcgiant_best_offer_enabled' => '1' ) );
list( , $calls ) = push( $auction );
$body = $calls[0][1] ?? '';
check( 'REVISE 8. the auction with bids: ReviseItem, with no format, duration, price, quantity or Best Offer', array( names_of( $calls ), array_values( array_intersect( (array) fields_of( $body ), array( 'ListingType', 'ListingDuration', 'StartPrice', 'Quantity', 'BestOfferDetails' ) ) ) ), array( array( 'ReviseItem' ), array() ) );
$auction_settings = exporter()->get_export_settings( 104 );
check( '  its settings read as the auction it is: Chinese, 7 days, whatever the shop\'s default', array( $auction_settings['listing_type'], $auction_settings['listing_duration'] ), array( 'Chinese', 'Days_7' ) );

// Decision 16 is for imported auctions (the owner's ruling): an auction the
// shop pushed is revised as 3.28.6 revised it, price, quantity and format
// included. Its bytes are held to 3.28.6's in the pushed section below.
$own_auction = new WC_Product( 105, array( 'name' => 'Own auction', 'description' => 'x', 'regular_price' => '15.00', 'stock' => 1, 'image_id' => own_photo( 9501 ) ) );
foreach ( array( '_ebay_item_id' => '110000000105', '_ebay_listing_type' => 'Chinese', '_ebay_listing_status' => 'Active', '_ebay_origin' => 'push', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) as $key => $value ) {
	update_post_meta( 105, $key, $value );
}
list( , $calls ) = push( $own_auction );
$body = $calls[0][1] ?? '';
check( '  an auction the shop pushed: its own category, condition, policies and location, and its price, quantity and format, as 3.28.6 sent them', array( names_of( $calls ), value_of( $body, 'e:PrimaryCategory/e:CategoryID' ), value_of( $body, 'e:Location' ), array_values( array_intersect( (array) fields_of( $body ), array( 'StartPrice', 'Quantity', 'ListingDuration', 'ListingType' ) ) ), value_of( $body, 'e:Quantity' ), value_of( $body, 'e:ListingType' ) ), array( array( 'ReviseItem' ), '183050', 'Austin, TX', array( 'StartPrice', 'Quantity', 'ListingDuration', 'ListingType' ), '1', 'Chinese' ) );
delete_post_meta( 105, '_ebay_export_listing_type' );
delete_post_meta( 105, '_ebay_export_listing_duration' );
update_post_meta( 105, '_tcgiant_best_offer_enabled', '1' );
list( , $calls ) = push( $own_auction );
check( '  the same auction with no format chosen for it, the shop\'s default Fixed Price: by the shop\'s format, as 3.28.6', array( names_of( $calls ), array_values( array_intersect( (array) fields_of( $calls[0][1] ?? '' ), array( 'StartPrice', 'Quantity', 'ListingType', 'BestOfferDetails' ) ) ), value_of( $calls[0][1] ?? '', 'e:ListingType' ) ), array( array( 'ReviseItem' ), array( 'StartPrice', 'Quantity', 'ListingType', 'BestOfferDetails' ), 'FixedPriceItem' ) );
update_post_meta( 105, '_ebay_export_listing_type', 'Chinese' );
update_post_meta( 105, '_ebay_export_listing_duration', 'Days_7' );
delete_post_meta( 105, '_tcgiant_best_offer_enabled' );
$auction_add = exporter()->build_item_xml_public( $own_auction, exporter()->get_export_settings( 105 ) );
check( '  a new auction (Verify builds an Add) still has them all', array( false !== strpos( $auction_add, '<StartPrice>15.00</StartPrice>' ), false !== strpos( $auction_add, '<Quantity>1</Quantity>' ), false !== strpos( $auction_add, '<ListingType>Chinese</ListingType>' ), false !== strpos( $auction_add, '<ListingDuration>Days_7</ListingDuration>' ) ), array( true, true, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSETTINGS: EBAY'S VALUES BY GROUP, EACH WHOLE\n" . str_repeat( '=', 124 ) . "\n";

/** A product imported with this record of eBay's values. */
function with_live( $id, array $live, array $meta = array() ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	new WC_Product( $id, array( 'name' => 'P' . $id, 'description' => 'x', 'stock' => 1 ) );
	foreach ( array( '_ebay_item_id' => '9' . $id, '_ebay_origin' => 'import', '_ebay_live' => array( 'item_id' => '9' . $id ) + $live ) + $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return exporter()->get_export_settings( $id );
}
$s = with_live( 191, array( 'condition_id' => '4000' ) );
check( 'a card or coin condition whose descriptors no answer carried: not eBay\'s, the shop\'s default', array( $s['_source']['condition'], $s['condition_type'], $s['ungraded_condition'] ), array( 'default', 'ungraded', '400010' ) );
$s = with_live( 192, array( 'condition_id' => '4000', 'descriptors' => array() ) );
check( '  one eBay gave with no descriptors: eBay\'s, as All Other with its ConditionID', array( $s['_source']['condition'], $s['item_type'], $s['condition_id'] ), array( 'live', 'other', '4000' ) );
$s = with_live( 193, array( 'location' => 'Torrance, CA' ) );
check( 'a location with no postal code: the shop\'s location and postal code, whole', array( $s['_source']['location'], $s['location'], $s['postal_code'] ), array( 'default', 'Austin, TX', '78701' ) );
$s = with_live( 194, array(), array( '_ebay_listing_type' => 'StoresFixedPrice', '_ebay_listing_duration' => 'GTC' ) );
check( 'a format the push does not make (StoresFixedPrice): the shop\'s', array( $s['_source']['format'], $s['listing_type'] ), array( 'default', 'FixedPriceItem' ) );
$s = with_live( 195, array( 'category_id' => '180006', 'category_name' => '' ) );
check( 'eBay\'s own category is never mapped as a retired ID', array( $s['category_id'], isset( $s['category_retired'] ) ), array( '180006', false ) );
check( 'where the card condition came from: the default, eBay, or the product', array( with_live( 196, array() )['_ungraded_from'], with_live( 197, array( 'descriptors' => array( array( 'name' => '40001', 'value' => '400011' ) ) ) )['_ungraded_from'], with_live( 198, array(), array( '_ebay_export_ungraded_condition' => '400012' ) )['_ungraded_from'] ), array( 'default', 'live', 'override' ) );
check( 'settings made by hand, with no sources: everything sent', array( TCGiant_Sync_Exporter::sends( array( 'category_id' => '1' ), 'category', true ), TCGiant_Sync_Exporter::sends( array( 'category_id' => '1' ), 'location', true ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nOVERRIDE: WHAT THE PRODUCT SETS GOES, WITH EBAY'S VALUES FOR THE REST\n" . str_repeat( '=', 124 ) . "\n";

$card = imported( 'card', 111, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_grade_value' => '9.5' ) );
list( , $calls ) = push( $card );
$body = $calls[0][1] ?? '';
check( 'OVERRIDE 9. only the grade set, 9.5: ConditionID 2750 with eBay\'s grader and certification, the grade as eBay\'s ID', array( value_of( $body, 'e:ConditionID' ), descriptors_of( $body ) ), array( '2750', array( '27501' => '275010', '27502' => '275021', '27503' => '81234567' ) ) );
check( '  and still no category, policies or location', array_values( array_intersect( (array) fields_of( $body ), array( 'PrimaryCategory', 'CategoryMappingAllowed', 'SellerProfiles', 'Location', 'PostalCode', 'DispatchTimeMax' ) ) ), array() );

$card = imported( 'card', 112, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_grade_value' => '10', '_ebay_export_grader_id' => '275010', '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_category_id' => '183454' ) );
list( , $calls ) = push( $card );
check( 'OVERRIDE 10. the grade "10", grader, type and category retyped as eBay has them: nothing changed, nothing of eBay\'s sent', ebays_own_in( $calls[0][1] ?? '' ), array() );
$card = imported( 'card', 118, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_grade_value' => '275020' ) );
list( , $calls ) = push( $card );
check( '  eBay\'s own ID for that grade, 275020, stored: the same grade, nothing sent', ebays_own_in( $calls[0][1] ?? '' ), array() );
$card_set = exporter()->get_export_settings( 101 );
check( '  the card\'s settings hold its grade by its label, and its condition as eBay\'s', array( $card_set['item_type'], $card_set['condition_type'], $card_set['grader_id'], $card_set['grade_value'], $card_set['cert_number'], $card_set['_source']['condition'] ), array( 'tcg', 'graded', '275010', '10', '81234567', 'live' ) );
$grade_ids = array();
foreach ( TCGiant_Sync_Catalog::GRADE_IDS_TCG as $label => $id ) {
	$grade_ids[ $label ] = TCGiant_Sync_Exporter::tcg_grade_id( $label );
}
check( '  every grade label to eBay\'s ID: the 19 numbers and the 4 Authentics', array( count( $grade_ids ), $grade_ids['10'], $grade_ids['1'], $grade_ids['Authentic - Coloured'], TCGiant_Sync_Exporter::tcg_grade_id( '275020' ), TCGiant_Sync_Exporter::tcg_grade_id( 'Mint+' ) ), array( 23, '275020', '2750218', '2750222', '275020', '' ) );
$card = imported( 'card', 113, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_grade_value' => 'Mint+' ) );
list( , $calls, $warned ) = push( $card );
check( '  a grade eBay has no ID for: not sent, and said, as 3.28.x does', array( descriptors_of( $calls[0][1] ?? '' ), count( array_filter( $warned, function ( $w ) { return false !== strpos( $w, 'the grade "Mint+" of WC #113 is not one eBay takes' ); } ) ) ), array( array( '27501' => '275010', '27503' => '81234567' ), 1 ) );

$card = imported( 'card', 114, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_category_id' => '261328' ) );
list( , $calls ) = push( $card );
$body = $calls[0][1] ?? '';
check( 'OVERRIDE 11. a category of its own, 261328: sent, with CategoryMappingAllowed and the whole condition eBay has', array( value_of( $body, 'e:PrimaryCategory/e:CategoryID' ), value_of( $body, 'e:CategoryMappingAllowed' ), value_of( $body, 'e:ConditionID' ), descriptors_of( $body ), in_array( 'SellerProfiles', (array) fields_of( $body ), true ) ), array( '261328', 'true', '2750', array( '27501' => '275010', '27502' => '275020', '27503' => '81234567' ), false ) );

TCGiant_Sync_OAuth::$settings = array( 'marketplace' => 'EBAY_US' );
$legacy2 = new WC_Product( 115, array( 'name' => 'Old card', 'description' => 'x', 'regular_price' => '9.00', 'stock' => 1, 'image_id' => own_photo( 9302 ) ) );
foreach ( array( '_ebay_item_id' => '256100000115', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_export_category_id' => '183454' ) as $key => $value ) {
	update_post_meta( 115, $key, $value );
}
$API->answers['GetItem'] = new WP_Error( 'http_error', 'eBay did not answer' );
list( $res, $calls, $warned ) = push( $legacy2 );
check( 'OVERRIDE 12. an old import moved to 183454, its condition not known here: read first with one GetItem; that read failing, refused, saying what to do', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', names_of( $calls ), is_wp_error( $res ) ? $res->get_error_message() : '' ), array( 'missing_export_settings', array( 'GetItem' ), 'Cannot push to eBay — missing required settings: Condition — the plugin has not read this listing\'s condition on eBay, and the category set on this product needs one it takes: set the whole condition on the product\'s eBay tab (Item Type, Graded or Ungraded, and the grade or the condition), or run Fetch Inventory so the listing is read' ) );
check( '  and says why', $warned, array( 'Push: could not read eBay listing 256100000115 of WC #115 (eBay did not answer), so its condition could not be checked, and the Update was not sent.' ) );
list( $res, $calls, $warned ) = push( $legacy2 );
check( '  pushed again within the hour: not read again, refused, and said', array( names_of( $calls ), is_wp_error( $res ) ? $res->get_error_code() : 'pushed', $warned ), array( array(), 'missing_export_settings', array( 'Push: eBay listing 256100000115 of WC #115 could not be read in the last hour, so its condition could not be checked, and the Update was not sent. It is read again after the hour.' ) ) );
check( '  the hour held by a transient, for that listing alone', array_keys( array_filter( $GLOBALS['W']['transients'], function ( $v, $k ) { return 0 === strpos( $k, 'tcgiant_sync_live_read_failed_' ); }, ARRAY_FILTER_USE_BOTH ) ), array( 'tcgiant_sync_live_read_failed_256100000115' ) );
forget_failed_reads();
$API->answers['GetItem'] = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '256100000115', 'ConditionID' => '2750', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( array( 'Name' => '27501', 'Value' => '275010' ), array( 'Name' => '27502', 'Value' => '275020' ) ) ), 'PrimaryCategory' => array( 'CategoryID' => '183050', 'CategoryName' => 'Non-Sport' ), 'Site' => 'US' ) );
list( $res, $calls ) = push( $legacy2 );
$body = $calls[1][1] ?? '';
check( 'Q10. read: a PSA 10 on eBay - the category of its own goes with eBay\'s graded condition', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $body, 'e:PrimaryCategory/e:CategoryID' ), value_of( $body, 'e:ConditionID' ), descriptors_of( $body ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), '183454', '2750', array( '27501' => '275010', '27502' => '275020' ) ) );
check( '  recorded as read whole, with what the answer had', array( TCGiant_Sync_Ebay_Live::snapshot( 115 )['full'] ?? null, TCGiant_Sync_Ebay_Live::snapshot( 115 )['category_id'] ?? null, TCGiant_Sync_Ebay_Live::snapshot( 115 )['policies'] ?? null ), array( '1', '183050', array() ) );
list( $res, $calls ) = push( $legacy2 );
check( '  the next Update: no GetItem - once per listing', names_of( $calls ), array( 'ReviseFixedPriceItem' ) );
unset( $API->answers['GetItem'] );
card_shop();

$card = imported( 'card', 116, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_fulfillment_policy' => '250000077' ) );
list( , $calls ) = push( $card );
check( 'OVERRIDE 13. a shipping policy of its own: SellerProfiles with it and eBay\'s own return and payment policies', array( policies_of( $calls[0][1] ?? '' ), array_values( array_intersect( (array) fields_of( $calls[0][1] ?? '' ), array( 'PrimaryCategory', 'ConditionID', 'Location' ) ) ) ), array( array( '250000077', '240000012', '240000013' ), array() ) );

$card = imported( 'card', 117, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_follow_defaults' => '1' ) );
list( , $calls ) = push( $card );
$body = $calls[0][1] ?? '';
check( 'OVERRIDE 14. set to send the shop\'s defaults: 183050, 4000/400010, its policies, Austin 78701, handling time 3, Fixed Price GTC', array( value_of( $body, 'e:PrimaryCategory/e:CategoryID' ), value_of( $body, 'e:ConditionID' ), descriptors_of( $body ), policies_of( $body ), value_of( $body, 'e:Location' ), value_of( $body, 'e:PostalCode' ), value_of( $body, 'e:DispatchTimeMax' ), value_of( $body, 'e:ListingType' ) ), array( '183050', '4000', array( '40001' => '400010' ), array( '250000011', '250000012', '250000013' ), 'Austin, TX', '78701', '3', 'FixedPriceItem' ) );
update_post_meta( 117, '_ebay_export_follow_defaults', '' );
update_post_meta( 117, '_ebay_origin', 'push' );
list( , $calls ) = push( $card );
check( 'OVERRIDE 15. the same product, marked pushed instead: the same body', $calls[0][1] ?? '', $body );
update_post_meta( 117, '_ebay_export_follow_defaults', '0' );
list( , $calls ) = push( $card );
check( '  a pushed product set to keep eBay\'s values: none of eBay\'s sent', ebays_own_in( $calls[0][1] ?? '' ), array() );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT THE PLUGIN PUSHED IS SENT WHAT 3.28.6 SENT IT, BYTE FOR BYTE\n" . str_repeat( '=', 124 ) . "\n";

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/imported-revise-pushed.php' ) . ' 2>&1', $tcg_pushed );
$now  = json_decode( (string) end( $tcg_pushed ), true );
$then = json_decode( (string) file_get_contents( __DIR__ . '/support/imported-revise-3286.json' ), true );
foreach ( array( 'card_revise' => 'a graded card revised', 'part_revise' => 'a used part revised with the shop\'s defaults', 'part_policy' => 'a part with its own shipping policy revised', 'card_add' => 'a new product\'s Add (ADD 16)', 'card_ended' => 'a pushed card whose listing ended: Revise, GetItem, then the Add', 'unrecorded_under_auction' => 'a fixed-price listing with no format recorded, the shop\'s default now an auction (finding 6D): its price and quantity still sent', 'auction_revise' => 'a live auction the shop pushed, its format chosen for it: price, quantity and format revised as 3.28.6 (decision 16 is for imported auctions)', 'auction_default_fixed' => 'a live auction the shop pushed, no format chosen, the default Fixed Price: as 3.28.6', 'stores_fixed_bo' => 'a listing recorded as StoresFixedPrice with the product\'s own Best Offer: as 3.28.6, Best Offer included', 'fixed_own_auction' => 'a fixed-price listing whose product chose an auction format of its own (not 6B): as 3.28.6' ) as $key => $label ) {
	check( $label . ': the same calls, the same bytes', isset( $now[ $key ], $then[ $key ] ) && $now[ $key ] === $then[ $key ], true );
}
check( '  (3.28.6\'s were captured, not written: each holds its full item)', array( count( $then ), false !== strpos( $then['card_revise'][0][1] ?? '', '<SellerProfiles>' ), false !== strpos( $then['card_add'][0][1] ?? '', '<DispatchTimeMax>3</DispatchTimeMax>' ), false !== strpos( $then['stores_fixed_bo'][0][1] ?? '', '<BestOfferDetails>' ) ), array( 13, true, true, true ) );
/** 3.28.6's calls for $key, with one change made to its item. */
function then_with( array $then, $key, $from, $to ) {
	$calls = $then[ $key ] ?? array();
	foreach ( $calls as $i => $call ) {
		$calls[ $i ][1] = str_replace( $from, $to, $call[1] );
	}
	return array( $calls, 1 === substr_count( (string) ( $then[ $key ][0][1] ?? '' ), $from ) );
}
list( $want, $once ) = then_with( $then, 'card_pair', '<Value>400011</Value>', '<Value>400015</Value>' );
check( 'EXCEPTION (decision 17, finding 3): a pushed card in 183454 whose default condition is Excellent goes as 400015 where 3.28.6 sent 400011 - that, and no other byte', array( $once, isset( $now['card_pair'] ) && $now['card_pair'] === $want ), array( true, true ) );
list( $want, $once ) = then_with( $then, 'fixed_under_auction', '<Quantity>1</Quantity>', '<Quantity>4</Quantity>' );
check( 'EXCEPTION (finding 6B): a pushed fixed-price listing recorded as one, the default now an auction: its own quantity 4 where 3.28.6 sent an auction\'s 1 - that, and no other byte', array( $once, isset( $now['fixed_under_auction'] ) && $now['fixed_under_auction'] === $want ), array( true, true ) );
list( $want, $once ) = then_with( $then, 'fixed_under_auction_bo', '<Quantity>1</Quantity>', '<Quantity>4</Quantity>' );
$bo_block            = "<BestOfferDetails>\n\t<BestOfferEnabled>true</BestOfferEnabled>\n</BestOfferDetails>\n<ListingDetails>\n</ListingDetails>\n";
$once                = $once && 1 === substr_count( (string) ( $want[0][1] ?? '' ), "</SellerProfiles>\n" );
$want[0][1]          = str_replace( "</SellerProfiles>\n", "</SellerProfiles>\n" . $bo_block, (string) ( $want[0][1] ?? '' ) );
check( 'EXCEPTION (finding 6B, re-verify B): the same with the product\'s own Best Offer: its quantity 4, and BestOfferDetails by its own fixed-price format, where 3.28.6 sent 1 and none - those, and no other byte', array( $once, isset( $now['fixed_under_auction_bo'] ) && $now['fixed_under_auction_bo'] === $want ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nADD: A NEW LISTING IN PLACE OF AN IMPORTED ONE CARRIES EBAY'S VALUES\n" . str_repeat( '=', 124 ) . "\n";

/** eBay's answer to GetItem for an ended listing. */
function ended_answer( $name, $listed, $sold ) {
	$item                                    = $GLOBALS['IMPORT']['answers'][ $name ];
	$item['Quantity']                        = (string) $listed;
	$item['SellingStatus']['QuantitySold']   = (string) $sold;
	$item['SellingStatus']['ListingStatus']  = 'Completed';
	return array( 'Ack' => 'Success', 'Item' => $item );
}

// The caliper's record holds nothing of eBay's yet: the ended listing's
// answer gives it, in the one GetItem the push makes.
$part = imported( 'part', 121, array( 'image_id' => $part_photos[0], 'stock' => 2, 'regular_price' => '120.00' ) );
delete_post_meta( 121, '_ebay_live' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_answer( 'part', 1, 0 );
list( $res, $calls ) = push( $part );
$add = end( $calls )[1] ?? '';
check( 'ADD 17. the ended caliper: Revise refused, one GetItem, then AddFixedPriceItem', array( names_of( $calls ), $res['action'] ?? null ), array( array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ), 'relisted' ) );
check( '  carrying 33564, ConditionID 3000, its policies, Columbus 43215 and handling time 1', array( value_of( $add, 'e:PrimaryCategory/e:CategoryID' ), value_of( $add, 'e:ConditionID' ), policies_of( $add ), value_of( $add, 'e:Location' ), value_of( $add, 'e:PostalCode' ), value_of( $add, 'e:DispatchTimeMax' ) ), array( '33564', '3000', array( '240000021', '240000022', '240000023' ), 'Columbus, OH', '43215', '1' ) );
check( '  and none of the shop\'s defaults', array( false !== strpos( $add, '183050' ), false !== strpos( $add, 'Austin' ), false !== strpos( $add, '25000001' ) ), array( false, false, false ) );
check( '  a whole item: price, quantity, photos, format', array( value_of( $add, 'e:StartPrice' ), value_of( $add, 'e:Quantity' ), value_of( $add, 'e:ListingType' ), value_of( $add, 'e:ListingDuration' ), value_of( $add, 'e:PictureDetails/e:PictureURL' ) ), array( '120.00', '2', 'FixedPriceItem', 'GTC', 'https://shop.example/wp-content/uploads/photo-9201.jpg' ) );
check( '  the answer recorded eBay\'s values', ( get_post_meta( 121, '_ebay_live', true )['category_id'] ?? null ), '33564' );

// The auction, ended unsold: relisted as the auction it is.
$auction = imported( 'auction', 122, array( 'image_id' => own_photo( 9402 ), 'regular_price' => '37.00' ) );
update_post_meta( 122, '_ebay_listing_status', 'Ended' );
$API->answers['ReviseItem'] = new WP_Error( 'api_error', 'The auction has already been closed.' );
$API->answers['GetItem']    = ended_answer( 'auction', 1, 0 );
$API->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '256100009122', 'StartTime' => '2026-11-02T10:00:00.000Z', 'EndTime' => '2026-11-09T10:00:00.000Z' );
$API->calls                 = array();
exporter()->process_single_push( 122 );
$relist = array_values( array_filter( $API->calls, function ( $c ) { return 'RelistItem' === $c[0]; } ) );
check( 'ADD 18. the ended imported auction: Revise refused, one GetItem, then one RelistItem - no Add', names_of( $API->calls ), array( 'ReviseItem', 'GetItem', 'RelistItem' ) );
check( '  RelistItem with the shop\'s edits - title, description, photos - and no quantity, price or format: the auction\'s stay', array( value_of( $relist[0][1] ?? '', 'e:ItemID' ), array_values( array_diff( (array) fields_of( $relist[0][1] ?? '' ), array( 'ItemID', 'UUID' ) ) ) ), array( '256100000003', array( 'Title', 'Description', 'PictureDetails' ) ) );
check( '  its title and photo the product\'s, and well-formed', array( null !== as_xml( $relist[0][1] ?? '' ), value_of( $relist[0][1] ?? '', 'e:Title' ), value_of( $relist[0][1] ?? '', 'e:PictureDetails/e:PictureURL' ), false !== strpos( value_of( $relist[0][1] ?? '', 'e:Description' ), 'From eBay.' ) ), array( true, wc_get_product( 122 )->get_name(), 'https://shop.example/wp-content/uploads/own-9402.jpg', true ) );
$relist_logged = array_values( array_filter( $GLOBALS['W']['log'], function ( $row ) { return false !== strpos( $row[1], 'Push: WC #122 relisted on eBay as 256100009122' ); } ) );
check( '  the log says what it carried', $relist_logged[0][1] ?? '', 'Push: WC #122 relisted on eBay as 256100009122 (was 256100000003), with the product\'s title, description and photos.' );
check( '  and the description it carried is recorded, for the new listing, as a push records it', array( '' !== (string) get_post_meta( 122, '_tcgiant_desc_hash', true ), get_post_meta( 122, '_tcgiant_tpl_item', true ) ), array( true, '256100009122' ) );
check( '  the product follows the relist, still an auction, with the end eBay gave it', array( get_post_meta( 122, '_ebay_item_id', true ), get_post_meta( 122, '_ebay_listing_type', true ), get_post_meta( 122, '_ebay_end_time', true ), get_post_meta( 122, '_ebay_listing_status', true ), get_post_meta( 122, '_ebay_previous_item_id', true ), get_post_meta( 122, '_ebay_export_status', true ) ), array( '256100009122', 'Chinese', '2026-11-09T10:00:00.000Z', 'Active', '256100000003', 'pushed' ) );
check( '  still imported, and not marked as an auction the shop listed', array( get_post_meta( 122, '_ebay_origin', true ), get_post_meta( 122, '_ebay_auction_listed_item', true ) ), array( 'import', '' ) );
check( '  eBay\'s values go with it to the new number, as RelistItem keeps them', array( TCGiant_Sync_Ebay_Live::snapshot( 122 )['item_id'] ?? null, TCGiant_Sync_Ebay_Live::snapshot( 122 )['category_id'] ?? null ), array( '256100009122', '183454' ) );

// Its photos still eBay's: the relist keeps them as they are, and says so.
$auction_live             = $IMPORT['imported']['auction']['meta']['_ebay_live'];
$auction_live['pictures'] = array( 'https://i.ebayimg.com/images/g/AUC/s-l1600.jpg' );
$auction_photos           = photos_from( 9420, $auction_live['pictures'] );
$auction                  = imported( 'auction', 129, array( 'image_id' => $auction_photos[0], 'regular_price' => '37.00' ), array( '_ebay_live' => $auction_live ) );
update_post_meta( 129, '_ebay_listing_status', 'Ended' );
$API->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '256100009129', 'EndTime' => '2026-11-09T10:00:00.000Z' );
list( $res, $calls ) = push( $auction );
$relist = array_values( array_filter( $calls, function ( $c ) { return 'RelistItem' === $c[0]; } ) );
$relist_logged = array_values( array_filter( $GLOBALS['W']['log'], function ( $row ) { return false !== strpos( $row[1], 'relisted on eBay as 256100009129' ); } ) );
check( '  its photos still the listing\'s: title and description only, the photos kept on eBay, and said', array( array_values( array_diff( (array) fields_of( $relist[0][1] ?? '' ), array( 'ItemID', 'UUID' ) ) ), $relist_logged[0][1] ?? '' ), array( array( 'Title', 'Description' ), 'Push: WC #129 relisted on eBay as 256100009129 (was 256100000003), with the product\'s title and description, and its photos as they were on eBay.' ) );

// An Update of an unpublished or sold-out auction relists it, as 3.28.6's new
// listing did; Relist and Auto-Relist still keep to published products with
// stock.
class Sold_Out_Product extends WC_Product {
	public function is_in_stock() { return false; }
}
$draft = imported( 'auction', 130, array( 'image_id' => own_photo( 9430 ) ) );
$GLOBALS['W']['status'][130] = 'draft';
update_post_meta( 130, '_ebay_listing_status', 'Ended' );
$API->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '256100009130' );
list( $res ) = push( $draft );
check( '  a draft: relisted by its Update, not refused for being unpublished', is_array( $res ) ? $res['action'] : $res->get_error_message(), 'relisted' );
update_post_meta( 130, '_ebay_item_id', '256100000003' );
$res = exporter()->relist_ended_listing( 130, 'Bulk relist' );
check( '  but bulk Relist of it still refused: the product is not published', is_wp_error( $res ) ? $res->get_error_message() : 'relisted', 'the product is not published' );
unset( $GLOBALS['W']['status'][130] );
$saved_auction = $IMPORT['imported']['auction']['meta'];
$sold_out = new Sold_Out_Product( 1131, array( 'name' => 'Sold out', 'description' => 'x', 'stock' => null, 'image_id' => own_photo( 9431 ) ) );
foreach ( $saved_auction as $key => $value ) {
	update_post_meta( 1131, $key, $value );
}
update_post_meta( 1131, '_ebay_listing_status', 'Ended' );
$API->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '256100009131' );
list( $res ) = push( $sold_out );
check( '  one marked out of stock: relisted by its Update; by bulk Relist, refused', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), ( function () { update_post_meta( 1131, '_ebay_item_id', '256100000003' ); $r = exporter()->relist_ended_listing( 1131, 'Bulk relist' ); return is_wp_error( $r ) ? $r->get_error_message() : 'relisted'; } )() ), array( 'relisted', 'it has no stock to sell' ) );

// Bulk Relist of an imported listing whose values no read has recorded yet:
// its GetItem records them, and they go with the relist.
$auction = imported( 'auction', 127, array( 'image_id' => own_photo( 9405 ) ) );
delete_post_meta( 127, '_ebay_live' );
update_post_meta( 127, '_ebay_listing_status', 'Ended' );
$API->answers['GetItem']    = ended_answer( 'auction', 1, 0 );
$API->answers['RelistItem'] = array( 'Ack' => 'Success', 'ItemID' => '256100009127', 'EndTime' => '2026-11-09T10:00:00.000Z' );
$API->calls                 = array();
$res                        = exporter()->relist_ended_listing( 127, 'Bulk relist' );
check( 'bulk Relist of an imported auction: GetItem, RelistItem, and eBay\'s values recorded for the new listing', array( names_of( $API->calls ), $res['item_id'] ?? null, TCGiant_Sync_Ebay_Live::snapshot( 127 )['location'] ?? null ), array( array( 'GetItem', 'RelistItem' ), '256100009127', 'Torrance, CA' ) );
$auction = imported( 'auction', 128, array( 'image_id' => own_photo( 9406 ) ) );
update_post_meta( 128, '_ebay_listing_status', 'Ended' );
$API->answers['GetItem'] = ended_answer( 'auction', 1, 0 );
$API->calls              = array();
exporter()->relist_ended_listing( 128, 'Push', array( 'ItemID' => '256100000999' ) );
check( '  an answer in hand about another listing is not taken for this one\'s: eBay is asked', names_of( $API->calls )[0] ?? null, 'GetItem' );

$auction = imported( 'auction', 123, array( 'image_id' => own_photo( 9403 ), 'regular_price' => '37.00' ), array( '_ebay_export_listing_type' => 'FixedPriceItem', '_ebay_export_listing_duration' => 'GTC' ) );
update_post_meta( 123, '_ebay_listing_status', 'Ended' );
list( $res, $calls ) = push( $auction );
check( 'ADD 19. one the shop chose Fixed Price for: AddFixedPriceItem, as it chose', array( names_of( $calls ), value_of( end( $calls )[1] ?? '', 'e:ListingType' ) ), array( array( 'ReviseItem', 'GetItem', 'AddFixedPriceItem' ), 'FixedPriceItem' ) );

$odd = imported( 'part', 126, array( 'image_id' => $part_photos[0], 'stock' => 2 ) );
$odd_live                  = get_post_meta( 126, '_ebay_live', true );
$odd_live['category_id']   = '180006';
$odd_live['category_name'] = '';
update_post_meta( 126, '_ebay_live', $odd_live );
$API->answers['GetItem'] = array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => '256100000002', 'ListingType' => 'FixedPriceItem', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ), 'Site' => 'US' ) );
list( , $calls ) = push( $odd );
check( '  one eBay has in 180006, an ID the plugin\'s own list once had wrong: its new listing stays there', value_of( end( $calls )[1] ?? '', 'e:PrimaryCategory/e:CategoryID' ), '180006' );
$part = imported( 'part', 124, array( 'image_id' => $part_photos[0], 'stock' => 1, 'regular_price' => '120.00' ) );
$API->answers['GetItem'] = ended_answer( 'part', 1, 1 );
list( $res, $calls ) = push( $part );
check( 'ADD 20. ended, and eBay says 1 listed and 1 sold: no Add, no Relist, and the stock is 0 (3.27.0\'s guard)', array( names_of( $calls ), is_wp_error( $res ) ? $res->get_error_code() : 'pushed', wc_get_product( 124 )->get_stock_quantity() ), array( array( 'ReviseFixedPriceItem', 'GetItem' ), 'relist_refused', 0 ) );

$auction = imported( 'auction', 125, array( 'image_id' => own_photo( 9404 ), 'regular_price' => '37.00' ) );
update_post_meta( 125, '_ebay_origin', 'push' );
$API->answers['GetItem'] = ended_answer( 'auction', 1, 0 );
list( $res, $calls ) = push( $auction );
check( '  an auction the shop pushed, ended: a new listing in the shop\'s format, as before', array( names_of( $calls ), value_of( end( $calls )[1] ?? '', 'e:ListingType' ) ), array( array( 'ReviseItem', 'GetItem', 'AddFixedPriceItem' ), 'FixedPriceItem' ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['ReviseItem'], $API->answers['GetItem'], $API->answers['RelistItem'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nUNGRADED: THE SHOP'S DEFAULT CARD CONDITION GOES AS ITS PAIR WHERE EBAY DOES NOT LIST IT (DECISION 17)\n" . str_repeat( '=', 124 ) . "\n";

/** A new card, pushed: its descriptors and the warnings. */
function new_card( $id, $category, array $meta = array() ) {
	$product = new WC_Product( $id, array( 'name' => 'Card ' . $id, 'description' => 'x', 'stock' => 1, 'image_id' => own_photo( 9600 + $id % 100 ) ) );
	foreach ( array( '_ebay_export_category_id' => $category ) + $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	list( , $calls, $warned ) = push( $product );
	return array( descriptors_of( end( $calls )[1] ?? '' ), $warned );
}
TCGiant_Sync_OAuth::$settings['export_ungraded_condition'] = '400011';
list( $d, $w ) = new_card( 131, '183454' );
check( 'UNGRADED 21. the default Excellent (400011) in 183454: sent as Lightly Played (Excellent), 400015', $d, array( '40001' => '400015' ) );
check( '  with its line', $w, array( TCGiant_Sync_Exporter::ungraded_pair_warning( 131, '400011', '400015', '183454' ) ) );
check( '  in these words', $w[0] ?? '', 'Push: the default card condition "Excellent" in Settings is not one eBay\'s table of trading-card conditions lists for category 183454, CCG Individual Cards, so WC #131 was sent its pair there, "Lightly Played (Excellent)". Choose a condition on the product\'s eBay tab to send another.' );
list( $d, $w ) = new_card( 132, '183050' );
check( '  in 183050, where eBay lists it: as it is, nothing said', array( $d, $w ), array( array( '40001' => '400011' ), array() ) );
TCGiant_Sync_OAuth::$settings['export_ungraded_condition'] = '400015';
list( $d ) = new_card( 133, '183050' );
check( '  the default 400015 in 183050: sent as Excellent, 400011', $d, array( '40001' => '400011' ) );
TCGiant_Sync_OAuth::$settings['export_ungraded_condition'] = '400010';
list( $d, $w ) = new_card( 134, '183454' );
check( '  Near Mint or Better (400010): as it is everywhere', array( $d, $w ), array( array( '40001' => '400010' ), array() ) );
list( $d, $w ) = new_card( 135, '183454', array( '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400011' ) );
check( '  the product\'s own 400011 in 183454: sent as it is, with 3.28.1\'s warning', array( $d, count( $w ), 0 === strpos( $w[0] ?? '', 'Push: the card condition "Excellent" of WC #135 is not one' ) ), array( array( '40001' => '400011' ), 1, true ) );
check( '  the pair helper: only for the default, only where the pair is listed', array(
	TCGiant_Sync_Exporter::ungraded_pair( array( 'ungraded_condition' => '400012', 'category_id' => '183454', '_ungraded_from' => 'default' ) ),
	TCGiant_Sync_Exporter::ungraded_pair( array( 'ungraded_condition' => '400012', 'category_id' => '183454', '_ungraded_from' => 'live' ) ),
	TCGiant_Sync_Exporter::ungraded_pair( array( 'ungraded_condition' => '400012', 'category_id' => '183454', '_ungraded_from' => 'override' ) ),
	TCGiant_Sync_Exporter::ungraded_pair( array( 'ungraded_condition' => '400017', 'category_id' => '261328', '_ungraded_from' => 'default' ) ),
	TCGiant_Sync_Exporter::ungraded_pair( array( 'ungraded_condition' => '400011', 'category_id' => '11980', '_ungraded_from' => 'default' ) ),
	TCGiant_Sync_Exporter::ungraded_pair( array( 'ungraded_condition' => '400010', 'category_id' => '183454', '_ungraded_from' => 'default' ) ),
), array( '400016', '', '', '400013', '', '' ) );
$both_ways = 6 === count( TCGiant_Sync_Catalog::UNGRADED_TCG_PAIRS );
foreach ( TCGiant_Sync_Catalog::UNGRADED_TCG_PAIRS as $from => $to ) {
	$both_ways = $both_ways && (string) $from === (string) ( TCGiant_Sync_Catalog::UNGRADED_TCG_PAIRS[ $to ] ?? '' );
}
check( '  every pair goes both ways', $both_ways, true );
$coin = new WC_Product( 136, array( 'name' => 'Coin', 'description' => 'x', 'stock' => 1, 'image_id' => own_photo( 9636 ) ) );
foreach ( array( '_ebay_export_category_id' => '11980', '_ebay_export_category_name' => 'Coins & Paper Money:Coins: US:Small Cents', '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '8' ) as $key => $value ) {
	update_post_meta( 136, $key, $value );
}
list( , $calls ) = push( $coin );
check( '  a coin\'s condition (descriptor 2): untouched', descriptors_of( end( $calls )[1] ?? '' ), array( '2' => '8' ) );
list( $d ) = new_card( 137, '183454', array( '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10', '_ebay_export_cert_number' => str_repeat( '7', 31 ) ) );
check( 'UNGRADED 22. a 31-character card certification number is cut to 30', $d['27503'] ?? null, str_repeat( '7', 30 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nCOIN: A GRADED COIN'S CONDITION READ FROM EBAY, AND ITS SPECIFICS LEFT TO EBAY\n" . str_repeat( '=', 124 ) . "\n";

TCGiant_Sync_OAuth::$settings['export_coin_year'] = '1999';
$coin     = imported( 'coin', 141, array( 'image_id' => own_photo( 9701 ), 'regular_price' => '2400.00' ) );
$coin_set = exporter()->get_export_settings( 141 );
check( 'COIN 23. the imported coin reads as grader 15 (NGC), MS/PR 65, with its certification number', array( $coin_set['item_type'], $coin_set['condition_type'], $coin_set['grader_id'], $coin_set['grade_value'], $coin_set['cert_number'] ), array( 'coins', 'graded', '15', 'MS/PR 65', '6123456-001' ) );
list( , $calls ) = push( $coin );
$body = $calls[0][1] ?? '';
check( '  its revise sends no condition, and no Certification or Grade specific made from it', array( array_values( array_intersect( (array) fields_of( $body ), array( 'ConditionID', 'ConditionDescriptors', 'PrimaryCategory' ) ) ), false !== strpos( $body, '<Name>Certification</Name>' ), false !== strpos( $body, '<Name>Grade</Name>' ) ), array( array(), false, false ) );
check( '  nor the shop\'s default coin year (1999) as its Year', false !== strpos( $body, '1999' ), false );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = ended_answer( 'coin', 1, 0 );
list( , $calls ) = push( $coin );
$add = end( $calls )[1] ?? '';
check( '  ended, its new listing sends 1=15, 3=18, 4=55 and 5 its certification number, in 11980', array( names_of( $calls ), descriptors_of( $add ), value_of( $add, 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ), array( '1' => '15', '3' => '18', '4' => '55', '5' => '6123456-001' ), '11980' ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
unset( TCGiant_Sync_OAuth::$settings['export_coin_year'] );
$coin_set['_send_all'] = true;
check( '  the same coin\'s settings, if it followed the shop\'s defaults, would make the specifics', private_method( 'TCGiant_Sync_Exporter', 'collect_configured_specifics' )->invoke( exporter(), $coin_set ), array( 'Certification' => 'NGC', 'Grade' => 'MS/PR 65', 'Certification Number' => '6123456-001' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nASPECTS: CHECKED AGAINST THE CATEGORY THE LISTING IS IN\n" . str_repeat( '=', 124 ) . "\n";

/** eBay's required aspects, by category, played. */
class Aspects_API extends Template_World_API {
	public $asked = array();
	public function get_category_aspects( $category_id ) {
		$this->asked[] = (string) $category_id;
		return '183454' === (string) $category_id ? array( array( 'name' => 'Game', 'required' => true ) ) : array( array( 'name' => 'Sport', 'required' => true ) );
	}
}
$api_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$api_slot->setAccessible( true );
}
$aspects_api = new Aspects_API();
$api_slot->setValue( null, $aspects_api );
$card = imported( 'card', 151, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ) );
$res  = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $card );
check( 'ASPECTS 24. the imported card is checked against 183454, where it is, not the default 183050', array( $aspects_api->asked[0] ?? null, is_wp_error( $res ) ? $res->get_error_code() : 'pushed', is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'Game' ) ), array( '183454', 'missing_required_aspects', true ) );
$aspects_api->asked = array();
$res                = private_method( 'TCGiant_Sync_Exporter', 'do_push' )->invoke( exporter(), $legacy );
check( '  an import from before 3.28.8, its category not known: not checked, and pushed', array( $aspects_api->asked, is_array( $res ) ? $res['action'] : $res->get_error_code() ), array( array(), 'updated' ) );
$api_slot->setValue( null, $API );
check( '  checks_aspects: a new listing, a pushed product, a known category yes; an unknown one on a revise no', array(
	TCGiant_Sync_Exporter::checks_aspects( array( '_source' => array( 'category' => 'default' ), '_send_all' => false ), false ),
	TCGiant_Sync_Exporter::checks_aspects( array( '_source' => array( 'category' => 'default' ), '_send_all' => true ), true ),
	TCGiant_Sync_Exporter::checks_aspects( array( '_source' => array( 'category' => 'live' ), '_send_all' => false ), true ),
	TCGiant_Sync_Exporter::checks_aspects( array( '_source' => array( 'category' => 'default' ), '_send_all' => false ), true ),
), array( true, true, true, false ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nORIGIN: WHO MADE THE LISTING, FOR A PRODUCT FROM BEFORE 3.28.8\n" . str_repeat( '=', 124 ) . "\n";

/** A product linked to a listing that started on 5 January 2026, made on $made, with these meta. */
function legacy_product( $id, $made, array $meta ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['fields'][ $id ] = array( 'post_date_gmt' => $made );
	foreach ( array( '_ebay_item_id' => '2561000' . $id, '_ebay_start_time' => '2026-01-05T10:00:00.000Z' ) + $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}
$pushed_once = array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-06-01 10:00:00' );
// Its last push the one that listed it: four minutes after the listing
// started (round 8), the site's time here being UTC.
$pushed_it = array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-01-05 10:04:00' );
check( 'never pushed: imported', TCGiant_Sync_Ebay_Live::origin( legacy_product( 161, '2026-02-01 09:00:00', array() ) ), 'import' );
check( '  never pushed, but made before its listing started (linked by hand): imported all the same', TCGiant_Sync_Ebay_Live::origin( legacy_product( 162, '2025-12-01 09:00:00', array() ) ), 'import' );
check( 'pushed since, made after its listing started: imported, then updated', TCGiant_Sync_Ebay_Live::origin( legacy_product( 163, '2026-02-01 09:00:00', $pushed_once ) ), 'import' );
check( 'pushed, made before its listing started, its last push the one that listed it: the shop\'s', TCGiant_Sync_Ebay_Live::origin( legacy_product( 164, '2025-12-01 09:00:00', $pushed_it ) ), 'push' );
check( '  the same Updated a day later, or months later: imported (round 8 - an Update since, a Link and a re-link all look so)', array( TCGiant_Sync_Ebay_Live::origin( legacy_product( 192, '2025-12-01 09:00:00', array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-01-06 10:04:00' ) ) ), TCGiant_Sync_Ebay_Live::origin( legacy_product( 193, '2025-12-01 09:00:00', $pushed_once ) ) ), array( 'import', 'import' ) );
check( '  a draft\'s zero date, with a full sync log: nothing tells - not known, never the shop\'s for want of knowing (round 6)', TCGiant_Sync_Ebay_Live::origin( legacy_product( 165, '0000-00-00 00:00:00', $pushed_once + array( '_tcgiant_sync_log' => array_fill( 0, 20, array( 'decisions' => array( 'New product imported. All fields synced from eBay.' ) ) ) ) ) ), TCGiant_Sync_Ebay_Live::UNKNOWN );
check( '  its log still holding the import\'s first entry: imported', TCGiant_Sync_Ebay_Live::origin( legacy_product( 166, '', $pushed_once + array( '_tcgiant_sync_log' => array( array( 'decisions' => array( 'Price updated (eBay won)' ) ), array( 'decisions' => array( 'New product imported. All fields synced from eBay.' ) ) ) ) ) ), 'import' );
check( '  a last-pushed date or a pushed status counts as pushed (never pushed is not said); a pushed version alone, which Unlink and a copy leave behind, does not; the shop\'s only by a last push that listed it - a pushed status with no date is imported', array( TCGiant_Sync_Ebay_Live::origin( legacy_product( 167, '2025-12-01 09:00:00', array( '_ebay_export_last_pushed' => '2026-01-05 10:04:00' ) ) ), TCGiant_Sync_Ebay_Live::origin( legacy_product( 168, '2025-12-01 09:00:00', array( '_ebay_export_pushed_version' => '3.20.0' ) ) ), TCGiant_Sync_Ebay_Live::origin( legacy_product( 169, '2025-12-01 09:00:00', array( '_ebay_export_status' => 'pushed' ) ) ) ), array( 'push', 'import', 'import' ) );
/** A product from before 3.28.8 with no start recorded: its item number and these meta. */
function startless( $id, $made, array $meta ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['fields'][ $id ] = array( 'post_date_gmt' => $made );
	foreach ( array( '_ebay_item_id' => '2561000' . $id ) + $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}
check( '  with no start recorded: a pushed version alone is still never pushed - imported; a pushed status alone is pushed, and only a read can tell - not known', array( TCGiant_Sync_Ebay_Live::origin( startless( 196, '2025-12-01 09:00:00', array( '_ebay_export_pushed_version' => '3.20.0' ) ) ), TCGiant_Sync_Ebay_Live::origin( startless( 197, '2025-12-01 09:00:00', array( '_ebay_export_status' => 'pushed' ) ) ) ), array( 'import', TCGiant_Sync_Ebay_Live::UNKNOWN ) );
// A shop product linked by hand before 3.28.8 (3.17.0-3.28.7's Link: the
// item number and the start, no _ebay_sku and no origin), Updated since.
check( 'a Link by hand before 3.28.8, Updated since: imported', TCGiant_Sync_Ebay_Live::origin( legacy_product( 194, '2025-06-01 09:00:00', array( '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-03-01 10:00:00', '_ebay_sku' => 'HAND-194' ) ) ), 'import' );
check( '  the auction mark 3.28.0 put on every auction ever Updated says nothing: one made after its listing started is imported', array( TCGiant_Sync_Ebay_Live::origin( legacy_product( 170, '2026-02-01 09:00:00', $pushed_once + array( '_ebay_auction_listed_item' => '2561000170' ) ) ), TCGiant_Sync_Ebay_Live::origin( legacy_product( 175, '2026-02-01 09:00:00', array( '_ebay_auction_listed_item' => '2561000175' ) ) ) ), array( 'import', 'import' ) );
check( '  no listing: none; one recorded: as recorded', array( TCGiant_Sync_Ebay_Live::origin( 171 ), TCGiant_Sync_Ebay_Live::origin( legacy_product( 172, '2025-12-01 09:00:00', $pushed_once + array( '_ebay_origin' => 'import' ) ) ) ), array( '', 'import' ) );
check( 'judged only, unless kept', array( TCGiant_Sync_Ebay_Live::origin( 163 ), get_post_meta( 163, '_ebay_origin', true ), TCGiant_Sync_Ebay_Live::origin( 163, true ), get_post_meta( 163, '_ebay_origin', true ) ), array( 'import', '', 'import', 'import' ) );
check( 'follows_defaults: the shop\'s choice first, then the origin', array( TCGiant_Sync_Ebay_Live::follows_defaults( 164 ), TCGiant_Sync_Ebay_Live::follows_defaults( 161 ), TCGiant_Sync_Ebay_Live::follows_defaults( 171 ) ), array( true, false, true ) );
update_post_meta( 161, '_ebay_export_follow_defaults', '1' );
update_post_meta( 164, '_ebay_export_follow_defaults', '0' );
check( '  ticked or unticked on the tab', array( TCGiant_Sync_Ebay_Live::follows_defaults( 161 ), TCGiant_Sync_Ebay_Live::follows_defaults( 164 ) ), array( true, false ) );
check( '  one not known: never the shop\'s defaults for want of knowing', array( TCGiant_Sync_Ebay_Live::follows_defaults( 165 ), TCGiant_Sync_Ebay_Live::origin_follows( TCGiant_Sync_Ebay_Live::UNKNOWN ) ), array( false, false ) );

// The live check (round 6): WC #11755, "Lilo", made by an older import on
// 9 April 2026 from a listing on eBay since 3 February; SKU
// "EBAY-336419685448", the import's form for a listing with none, written
// with _ebay_sku before the item number by the import's own save; Updated
// once by 3.27.8; its own Item Type, grading and category; no start or end
// time, no record of eBay's values, and a full sync log. The preview judged
// it 'push'.
/** Lilo's state, under another number. */
function lilo_like( $id, $item ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['fields'][ $id ] = array( 'post_date_gmt' => '2026-04-09 08:00:00' );
	new WC_Product( $id, array( 'name' => 'Lilo', 'description' => 'x', 'regular_price' => '12.00', 'stock' => 1, 'sku' => 'EBAY-' . $item, 'image_id' => own_photo( 9900 + $id % 100 ) ) );
	foreach ( array( '_sku' => 'EBAY-' . $item, '_ebay_sku' => 'EBAY-' . $item, '_ebay_item_id' => $item, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_tcgiant_sync_log' => array_fill( 0, 20, array( 'decisions' => array( 'Price updated (eBay won)' ) ) ), '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_category_id' => '183454', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => '2026-10-28 15:00:00', '_ebay_export_pushed_version' => '3.27.8' ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $id;
}
check( 'LIVE CHECK. Lilo\'s state, no start time anywhere: imported, by the SKU the import made', TCGiant_Sync_Ebay_Live::origin( lilo_like( 176, '336419685448' ) ), 'import' );
check( '  the same SKU given by a sync to a product a push linked first (its _ebay_sku after the item number): not known', ( function () {
	lilo_like( 177, '336400000177' );
	delete_post_meta( 177, '_ebay_sku' );
	update_post_meta( 177, '_ebay_sku', 'EBAY-336400000177' );
	return TCGiant_Sync_Ebay_Live::origin( 177 );
} )(), TCGiant_Sync_Ebay_Live::UNKNOWN );

// Not known: the first Update reads the listing once, records when it
// started, judges and keeps the answer, and only then builds the body.
card_shop();
forget_failed_reads();
/** A product the shop Updated before 3.28.8, nothing telling who made its listing: no start, no SKU of the import's, a full log. */
function untold( $id, $made, $last_pushed = '2026-06-01 10:00:00' ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$GLOBALS['W']['fields'][ $id ] = array( 'post_date_gmt' => $made );
	$product = new WC_Product( $id, array( 'name' => 'Untold ' . $id, 'description' => 'x', 'regular_price' => '20.00', 'stock' => 1, 'image_id' => own_photo( 9950 + $id % 50 ) ) );
	foreach ( array( '_ebay_item_id' => '2561000' . $id, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active', '_ebay_export_status' => 'pushed', '_ebay_export_last_pushed' => $last_pushed, '_tcgiant_sync_log' => array_fill( 0, 20, array( 'decisions' => array( 'Price updated (eBay won)' ) ) ) ) as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
$started_answer = function ( $id ) {
	return read_answer( '2561000' . $id, array( 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ), 'PrimaryCategory' => array( 'CategoryID' => '33564' ), 'ConditionID' => '3000' ) );
};
$API->answers['GetItem'] = $started_answer( 178 );
list( $res, $calls ) = push( untold( 178, '2026-02-01 09:00:00' ) );
check( 'UNKNOWN 1. made after its listing started (5 January): one read, then kept as imported, and the Update sends none of eBay\'s own - only the product\'s own photos, which are not the listing\'s', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), get_post_meta( 178, '_ebay_origin', true ), get_post_meta( 178, '_ebay_origin_by', true ), get_post_meta( 178, '_ebay_start_time', true ), ebays_own_in( $calls[1][1] ?? '' ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), 'import', 'judged', '2026-01-05T10:00:00.000Z', array( 'PictureDetails' ) ) );
list( , $calls ) = push( wc_get_product( 178 ) );
check( '  pushed again: not read again', names_of( $calls ), array( 'ReviseFixedPriceItem' ) );
$API->answers['GetItem'] = $started_answer( 179 );
list( $res, $calls ) = push( untold( 179, '2025-12-01 09:00:00', '2026-01-05 10:03:00' ) );
$body = $calls[1][1] ?? '';
check( 'UNKNOWN 2. made before its listing started, its last push three minutes after the start (the push that listed it): one read, then kept as the shop\'s, and the Update sends the shop\'s defaults, as 3.28.6 did', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), get_post_meta( 179, '_ebay_origin', true ), value_of( $body, 'e:PrimaryCategory/e:CategoryID' ), value_of( $body, 'e:Location' ), policies_of( $body ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), 'push', '183050', 'Austin, TX', array( '250000011', '250000012', '250000013' ) ) );
list( , $calls ) = push( wc_get_product( 179 ) );
check( '  pushed again: not read again, the defaults still sent', array( names_of( $calls ), value_of( $calls[0][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem' ), '183050' ) );
$API->answers['GetItem'] = new WP_Error( 'http_request_failed', 'timeout' );
list( $res, $calls, $warned ) = push( untold( 180, '2025-12-01 09:00:00', '2026-01-05 10:03:00' ) );
check( 'UNKNOWN 3. the read failing: the Update goes as an imported listing\'s - none of the shop\'s defaults, only the product\'s own photos - nothing kept, and said', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), ebays_own_in( $calls[1][1] ?? '' ), get_post_meta( 180, '_ebay_origin', true ), $warned ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), array( 'PictureDetails' ), '', array( 'Push: could not read when eBay listing 2561000180 of WC #180 started (timeout), so who made it is not known yet: the Update went as for an imported listing, with only what this product sets of its own. The listing is read again at an Update after the hour.' ) ) );
list( $res, $calls, $warned ) = push( wc_get_product( 180 ) );
check( '  within the hour: not read, the same, and said', array( names_of( $calls ), ebays_own_in( $calls[0][1] ?? '' ), $warned ), array( array( 'ReviseFixedPriceItem' ), array( 'PictureDetails' ), array( 'Push: could not read when eBay listing 2561000180 of WC #180 started, in the last hour, so who made it is not known yet: the Update went as for an imported listing, with only what this product sets of its own. The listing is read again at an Update after the hour.' ) ) );
forget_failed_reads();
$API->answers['GetItem'] = $started_answer( 180 );
list( , $calls ) = push( wc_get_product( 180 ) );
check( '  after the hour: read, and judged (the shop\'s)', array( names_of( $calls ), get_post_meta( 180, '_ebay_origin', true ) ), array( array( 'GetItem', 'ReviseFixedPriceItem' ), 'push' ) );
$p = untold( 183, '2025-12-01 09:00:00' );
$p->data['stock'] = 0;
list( $res, $calls ) = push( $p );
check( '  refused anyway (no stock): no read, nothing kept', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', names_of( $calls ), get_post_meta( 183, '_ebay_origin', true ) ), array( 'out_of_stock', array(), '' ) );
$API->answers['GetItem'] = $started_answer( 184 );
untold( 184, '2026-02-01 09:00:00' );
$API->calls = array();
exporter()->process_single_push( 184 );
check( '  through process_single_push(): nothing kept before the push, then the read\'s verdict, said to be judged', array( names_of( $API->calls ), get_post_meta( 184, '_ebay_origin', true ), get_post_meta( 184, '_ebay_origin_by', true ), get_post_meta( 184, '_ebay_export_status', true ) ), array( array( 'GetItem', 'ReviseFixedPriceItem' ), 'import', 'judged', 'pushed' ) );
unset( $API->answers['GetItem'] );
legacy_product( 185, '2025-12-01 09:00:00', $pushed_it + array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) );
list( , $calls ) = push( new WC_Product( 185, array( 'name' => 'Pushed', 'description' => 'x', 'regular_price' => '20.00', 'stock' => 1, 'image_id' => own_photo( 9985 ) ) ) );
check( 'a product pushed and made before its listing started, its last push the one that listed it (its start recorded): the shop\'s, no read, the defaults sent', array( names_of( $calls ), value_of( $calls[0][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem' ), '183050' ) );
// Known to have ended: no read first - the ended branch's GetItem judges it.
foreach ( array( 181 => '2025-12-01 09:00:00', 182 => '2026-02-01 09:00:00', 195 => '2025-12-01 09:00:00' ) as $id => $made ) {
	untold( $id, $made, 195 === $id ? '2026-03-01 10:00:00' : '2026-01-05 10:03:00' );
	update_post_meta( $id, '_ebay_listing_status', 'Ended' );
}
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$ended_started = function ( $id ) {
	return read_answer( '2561000' . $id, array( 'ListingType' => 'FixedPriceItem', 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ), 'PrimaryCategory' => array( 'CategoryID' => '33564', 'CategoryName' => 'eBay Motors:Parts' ), 'ConditionID' => '3000' ) );
};
$API->answers['GetItem'] = $ended_started( 181 );
list( $res, $calls ) = push( wc_get_product( 181 ) );
check( 'UNKNOWN 4. ended, made before it started, its last push the one that listed it: judged the shop\'s from the ended branch\'s GetItem, and the new listing in the shop\'s default category', array( names_of( $calls ), $res['action'] ?? ( is_wp_error( $res ) ? $res->get_error_message() : null ), get_post_meta( 181, '_ebay_origin', true ), value_of( end( $calls )[1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ), 'relisted', 'push', '183050' ) );
$API->answers['GetItem'] = $ended_started( 195 );
list( $res, $calls ) = push( wc_get_product( 195 ) );
check( '  P11. ended, linked by hand before 3.28.8 and Updated since: the ended branch\'s read judges it imported, and the new listing in eBay\'s category', array( names_of( $calls ), get_post_meta( 195, '_ebay_origin', true ), value_of( end( $calls )[1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ), 'import', '33564' ) );
$API->answers['GetItem'] = $ended_started( 182 );
list( $res, $calls ) = push( wc_get_product( 182 ) );
check( '  made after it started: imported, and the new listing in eBay\'s category', array( names_of( $calls ), get_post_meta( 182, '_ebay_origin', true ), value_of( end( $calls )[1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem', 'GetItem', 'AddFixedPriceItem' ), 'import', '33564' ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
forget_failed_reads();
untold( 186, '2025-12-01 09:00:00' );
update_post_meta( 186, '_ebay_export_follow_defaults', '0' );
untold( 187, '2025-12-01 09:00:00' );
update_post_meta( 187, '_ebay_export_follow_defaults', '1' );
list( , $calls ) = push( wc_get_product( 186 ) );
list( , $calls2 ) = push( wc_get_product( 187 ) );
check( 'UNKNOWN 6. the shop\'s own choice on the tab: no read - unticked, eBay\'s values; ticked, the shop\'s defaults', array( names_of( $calls ), ebays_own_in( $calls[0][1] ?? '' ), names_of( $calls2 ), value_of( $calls2[0][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( array( 'ReviseFixedPriceItem' ), array( 'PictureDetails' ), array( 'ReviseFixedPriceItem' ), '183050' ) );
untold( 188, '2026-02-01 09:00:00' );
check( '  an answer about another listing teaches nothing: no start kept, still not known', array( TCGiant_Sync_Ebay_Live::learn_origin( 188, array( 'ItemID' => '256100000999', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) ), get_post_meta( 188, '_ebay_start_time', true ), get_post_meta( 188, '_ebay_origin', true ) ), array( TCGiant_Sync_Ebay_Live::UNKNOWN, '', '' ) );

// Round 7: the read decides only on evidence, and a read that cannot tell
// keeps 'import' - nothing of the shop's sent, and not read again.
forget_failed_reads();
$API->answers['GetItem'] = read_answer( '2561000189', array( 'RelistParentID' => '256100000001', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) );
list( $res, $calls ) = push( untold( 189, '2025-12-01 09:00:00' ) );
check( 'UNKNOWN 7. made before its listing started, but that listing a relist (RelistParentID): kept imported, judged - never the shop\'s by the date', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), get_post_meta( 189, '_ebay_origin', true ), get_post_meta( 189, '_ebay_origin_by', true ), value_of( $calls[1][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), 'import', 'judged', '' ) );
list( , $calls ) = push( wc_get_product( 189 ) );
check( '  pushed again: not read again', names_of( $calls ), array( 'ReviseFixedPriceItem' ) );
$API->answers['GetItem'] = $started_answer( 190 );
list( , $calls ) = push( untold( 190, '0000-00-00 00:00:00' ) );
list( , $calls2 ) = push( wc_get_product( 190 ) );
check( 'UNKNOWN 8. a draft\'s zero date: read once, kept imported, never read again', array( names_of( $calls ), get_post_meta( 190, '_ebay_origin', true ), names_of( $calls2 ) ), array( array( 'GetItem', 'ReviseFixedPriceItem' ), 'import', array( 'ReviseFixedPriceItem' ) ) );
$keep_siteurl                       = $GLOBALS['W']['options']['siteurl'] ?? null;
$GLOBALS['W']['options']['siteurl'] = 'https://shop.example';
$API->answers['GetItem']            = read_answer( '2561000191', array( 'RelistParentID' => '256100000002', 'ApplicationData' => TCGiant_Sync_API::push_mark( 191 ), 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) );
list( $res, $calls ) = push( untold( 191, '2025-12-01 09:00:00' ) );
check( 'UNKNOWN 9. a relist carrying this product\'s own push mark (3.28.7): the shop\'s, and the shop\'s defaults sent', array( '' !== TCGiant_Sync_API::push_mark( 191 ), names_of( $calls ), get_post_meta( 191, '_ebay_origin', true ), value_of( $calls[1][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ) ), array( true, array( 'GetItem', 'ReviseFixedPriceItem' ), 'push', '183050' ) );
check( '  a read of a product whose origin is kept already changes nothing: the shop\'s stays, though the read shows a relist', array( TCGiant_Sync_Ebay_Live::learn_origin( 179, array( 'ItemID' => '2561000179', 'RelistParentID' => '1', 'ListingDetails' => array( 'StartTime' => '2026-01-05T10:00:00.000Z' ) ) ), get_post_meta( 179, '_ebay_origin', true ) ), array( 'push', 'push' ) );
if ( null === $keep_siteurl ) {
	unset( $GLOBALS['W']['options']['siteurl'] );
} else {
	$GLOBALS['W']['options']['siteurl'] = $keep_siteurl;
}
unset( $API->answers['GetItem'] );
forget_failed_reads();

// The push keeps it, before it marks the product pushed: an imported product
// updated once stays imported.
$p = imported( 'part', 173, array( 'image_id' => $part_photos[0], 'stock' => 2 ), array( '_ebay_origin' => '' ) );
$GLOBALS['W']['fields'][173] = array( 'post_date_gmt' => '2026-02-01 09:00:00' );
$API->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Success', 'ItemID' => '256100000002' );
exporter()->process_single_push( 173 );
check( 'ORIGIN 25. an imported product, Updated: kept as imported before the push marks it pushed, said to be judged', array( get_post_meta( 173, '_ebay_export_status', true ), get_post_meta( 173, '_ebay_origin', true ), get_post_meta( 173, '_ebay_origin_by', true ) ), array( 'pushed', 'import', 'judged' ) );
exporter()->process_single_push( 173 );
check( '  and its next Update still sends none of eBay\'s fields', ebays_own_in( end( $API->calls )[1] ?? '' ), array() );
unset( $API->answers['ReviseFixedPriceItem'] );
$fresh = new WC_Product( 174, array( 'name' => 'New', 'description' => 'x', 'stock' => 1, 'image_id' => own_photo( 9774 ) ) );
exporter()->process_single_push( 174 );
check( 'ORIGIN 26. a product a push created: the shop\'s, the push saying it wrote that', array( get_post_meta( 174, '_ebay_item_id', true ), get_post_meta( 174, '_ebay_origin', true ), get_post_meta( 174, '_ebay_origin_by', true ) ), array( '110000000001', 'push', 'push' ) );

// The upgrade's pass (sweep_origins()) has its own harness:
// test-origin-sweep.php.

// ─────────────────────────────────────────────────────────────────────────────
echo "\nUNKNOWN: WHERE THE PLUGIN CANNOT TELL, THE FIELD IS LEFT OUT OR THE PUSH REFUSED\n" . str_repeat( '=', 124 ) . "\n";

/** A product imported before 3.28.8: no record of eBay's values, no origin, never pushed. */
function old_import( $id, array $meta ) {
	unset( $GLOBALS['W']['meta'][ $id ] );
	$product = new WC_Product( $id, array( 'name' => 'Old ' . $id, 'description' => 'x', 'regular_price' => '9.00', 'stock' => 1, 'image_id' => own_photo( 9800 + $id % 100 ) ) );
	foreach ( array( '_ebay_item_id' => '2561000' . $id, '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_status' => 'Active' ) + $meta as $key => $value ) {
		update_post_meta( $id, $key, $value );
	}
	return $product;
}
/** A refusal: its code, the calls made, and whether it says this. */
function refused_with( array $pushed, $words ) {
	list( $res, $calls ) = $pushed;
	return array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', names_of( $calls ), is_wp_error( $res ) && false !== strpos( $res->get_error_message(), $words ) );
}
/** eBay's GetItem answer for a listing: its item number and these fields. */
function read_answer( $item_id, array $fields ) {
	return array( 'Ack' => 'Success', 'Item' => $fields + array( 'ItemID' => $item_id, 'Site' => 'US', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ) ) );
}
$condition_fields = array( 'ConditionID', 'ConditionDescriptors' );
card_shop();

list( $res, $calls ) = push( old_import( 201, array( '_ebay_export_item_type' => 'tcg' ) ) );
check( 'FIX 1. an old import saved with Step 1 "Trading Cards" alone: no condition sent - not the shop\'s ungraded Near Mint', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), array_values( array_intersect( (array) fields_of( $calls[0][1] ?? '' ), $condition_fields ) ), false !== strpos( $calls[0][1] ?? '', '400010' ) ), array( 'updated', array( 'ReviseFixedPriceItem' ), array(), false ) );
check( '  its condition is not the product\'s, so not the revise\'s', exporter()->get_export_settings( 201 )['_source']['condition'], 'default' );
$API->answers['GetItem'] = read_answer( '2561000202', array( 'PrimaryCategory' => array( 'CategoryID' => '183454' ), 'ConditionID' => '2750', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( array( 'Name' => '27501', 'Value' => '275013' ), array( 'Name' => '27502', 'Value' => '275020' ) ) ) ) );
list( $res, $calls ) = push( old_import( 202, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010' ) ) );
check( '  Graded with a grader and no grade of its own: part of one, so the listing is read once (round 4), and the grader goes with eBay\'s grade, nothing of the shop\'s', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), descriptors_of( $calls[1][1] ?? '' ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), array( '27501' => '275010', '27502' => '275020' ) ) );
$ungraded_live = $card_live;
$ungraded_live['descriptors'] = array( array( 'name' => '40001', 'value' => '400010' ) );
$ungraded_live['condition_id'] = '4000';
list( $res, $calls ) = push( imported( 'card', 247, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_live' => $ungraded_live, '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010' ) ) );
check( '  Graded with a grader and no grade over eBay\'s Ungraded card: still no grade, so not whole - neither sent nor refused', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), array_values( array_intersect( (array) fields_of( $calls[0][1] ?? '' ), $condition_fields ) ) ), array( 'updated', array( 'ReviseFixedPriceItem' ), array() ) );
$API->answers['GetItem'] = read_answer( '2561000235', array( 'PrimaryCategory' => array( 'CategoryID' => '183454' ), 'ConditionID' => '4000', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( 'Name' => '40001', 'Value' => '400010' ) ) ) );
list( $res, $calls ) = push( old_import( 235, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_ungraded_condition' => '400011' ) ) );
check( 'R4 1. a card condition of its own with no Graded or Ungraded, eBay\'s condition not read: read once, then sent whole with eBay\'s Ungraded', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $calls[1][1] ?? '', 'e:ConditionID' ), descriptors_of( $calls[1][1] ?? '' ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), '4000', array( '40001' => '400011' ) ) );
list( , $calls ) = push( wc_get_product( 235 ) );
check( '  pushed again: not read again', names_of( $calls ), array( 'ReviseFixedPriceItem' ) );
$API->answers['GetItem'] = read_answer( '2561000236', array( 'PrimaryCategory' => array( 'CategoryID' => '33564' ), 'ConditionID' => '1000' ) );
list( $res, $calls ) = push( old_import( 236, array( '_ebay_export_condition_id' => '3000' ) ) );
check( '  a legacy ConditionID 3000 with no Item Type: read once, then sent over eBay\'s 1000', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $calls[1][1] ?? '', 'e:ConditionID' ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), '3000' ) );
$API->answers['GetItem'] = read_answer( '2561000243', array( 'PrimaryCategory' => array( 'CategoryID' => '20081' ) ) );
list( $res, $calls ) = push( old_import( 243, array( '_ebay_export_condition_id' => '3000' ) ) );
check( '  read, and the listing has no condition on eBay: nothing to make it whole, so none sent, and not read again', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), array_values( array_intersect( (array) fields_of( $calls[1][1] ?? '' ), $condition_fields ) ), names_of( push( wc_get_product( 243 ) )[1] ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), array(), array( 'ReviseFixedPriceItem' ) ) );
unset( $API->answers['GetItem'] );
check( '  nor any other part of one: a grade with no grader, Ungraded with no condition, a whole grade with no Item Type', array(
	exporter()->get_export_settings( old_import( 217, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grade_value' => '9' ) )->get_id() )['_source']['condition'],
	exporter()->get_export_settings( old_import( 218, array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'ungraded' ) )->get_id() )['_source']['condition'],
	exporter()->get_export_settings( old_import( 219, array( '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9' ) )->get_id() )['_source']['condition'],
), array( 'default', 'default', 'default' ) );
list( $res, $calls ) = push( old_import( 203, array( '_ebay_export_item_type' => 'other' ) ) );
check( '  All Other with no condition of its own: none sent, not the shop\'s 1000', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), array_values( array_intersect( (array) fields_of( $calls[0][1] ?? '' ), $condition_fields ) ) ), array( 'updated', array() ) );

$whole_graded = array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9' );
TCGiant_Sync_OAuth::$settings['export_cert_number'] = '99999999';
TCGiant_Sync_OAuth::$settings['export_grader_id']   = '275030';
list( $res, $calls ) = push( old_import( 204, $whole_graded + array( '_ebay_export_category_id' => '183454', '_ebay_export_cert_number' => '44556677' ) ) );
$body = $calls[0][1] ?? '';
check( '  a whole graded card of its own with its own category, 183454: both sent, the condition all the product\'s, its own certification number - none of the shop\'s grader or certification number', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), value_of( $body, 'e:PrimaryCategory/e:CategoryID' ), value_of( $body, 'e:ConditionID' ), descriptors_of( $body ) ), array( 'updated', '183454', '2750', array( '27501' => '275010', '27502' => TCGiant_Sync_Exporter::tcg_grade_id( '9' ), '27503' => '44556677' ) ) );
list( $res, $calls ) = push( old_import( 220, $whole_graded + array( '_ebay_export_category_id' => '183454' ) ) );
check( '  and with none of its own, no certification number: not the shop\'s', array_key_exists( '27503', descriptors_of( $calls[0][1] ?? '' ) ), false );
$s = exporter()->get_export_settings( old_import( 205, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400012', '_ebay_export_category_id' => '183454' ) )->get_id() );
check( '  ungraded with a card condition of its own: the whole group the product\'s', array( $s['_source']['condition'], $s['condition_type'], $s['ungraded_condition'], $s['grader_id'], $s['cert_number'], $s['condition_id'], $s['_ungraded_from'] ), array( 'override', 'ungraded', '400012', '', '', '4000', 'override' ) );
unset( TCGiant_Sync_OAuth::$settings['export_cert_number'], TCGiant_Sync_OAuth::$settings['export_grader_id'] );

$part_nocond = $IMPORT['imported']['part']['meta']['_ebay_live'];
unset( $part_nocond['condition_id'], $part_nocond['descriptors'] );
list( $res, $calls ) = push( imported( 'part', 206, array( 'image_id' => $part_photos[0], 'stock' => 2 ), array( '_ebay_live' => $part_nocond, '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '3000' ) ) );
check( '  All Other with its own condition, in the category eBay has (33564): ConditionID 3000 alone', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), ebays_own_in( $calls[0][1] ?? '' ), value_of( $calls[0][1] ?? '', 'e:ConditionID' ) ), array( 'updated', array( 'ConditionID' ), '3000' ) );

TCGiant_Sync_OAuth::$settings['export_category_id'] = '33564';
check( 'FIX 2c. a whole graded card of its own, the listing\'s category not known: read first, and that read failing, refused - never built against the shop\'s default category', refused_with( push( old_import( 207, $whole_graded ) ), 'eBay Category — the plugin has not read which category this listing is in on eBay, so the condition set on this product cannot be checked against it: run Fetch Inventory so the listing is read, or choose its category with Browse eBay Categories' ), array( 'missing_export_settings', array( 'GetItem' ), true ) );
forget_failed_reads();
$API->answers['GetItem'] = read_answer( '2561000207', array( 'PrimaryCategory' => array( 'CategoryID' => '183454', 'CategoryName' => 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards' ), 'ConditionID' => '4000', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( 'Name' => '40001', 'Value' => '400010' ) ) ) );
list( $res, $calls ) = push( wc_get_product( 207 ) );
$body = $calls[1][1] ?? '';
check( 'Q10. the read answering 183454: the card\'s own graded condition sent, no category, once', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $body, 'e:ConditionID' ), descriptors_of( $body ), in_array( 'PrimaryCategory', (array) fields_of( $body ), true ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), '2750', array( '27501' => '275010', '27502' => TCGiant_Sync_Exporter::tcg_grade_id( '9' ) ), false ) );
$API->answers['GetItem'] = read_answer( '2561000207', array( 'PrimaryCategory' => array( 'CategoryID' => '33564' ), 'ConditionID' => '3000' ) );
list( $res, $calls ) = push( wc_get_product( 207 ) );
check( '  pushed again: no second read, though eBay would now answer otherwise', names_of( $calls ), array( 'ReviseFixedPriceItem' ) );
unset( $API->answers['GetItem'] );
card_shop();
check( '  a new listing (an Add in place of an ended one) is not held to the pair the listing it replaces would have: the caliper moved to 183454', array( is_wp_error( exporter()->validate_export_settings( exporter()->get_export_settings( imported( 'part', 232, array( 'image_id' => $part_photos[0] ), array( '_ebay_export_category_id' => '183454' ) )->get_id() ), false ) ), exporter()->validate_export_settings( exporter()->get_export_settings( 232 ), true ) ), array( true, true ) );
$coin_whole = array( '_ebay_export_item_type' => 'coins', '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '15', '_ebay_export_grade_value' => 'MS/PR 65' );
list( $res ) = push( old_import( 208, $coin_whole ) );
check( '  a whole coin of its own in a card shop: the same refusal, and not held to the shop\'s default card category', array( is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'has not read which category' ), is_wp_error( $res ) && false !== strpos( $res->get_error_message(), 'Coins item' ) ), array( true, false ) );
check( '  the same coin set to send the shop\'s defaults: held to them, as before', ( function () {
	update_post_meta( 208, '_ebay_export_follow_defaults', '1' );
	$r = exporter()->validate_export_settings( exporter()->get_export_settings( 208 ), false );
	delete_post_meta( 208, '_ebay_export_follow_defaults' );
	return is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'this is a Coins item' );
} )(), true );

$mem_item = '2561000209';
update_option( TCGiant_Sync_Category_Memory::option_name( TCGiant_Sync_Category_Memory::chunk_of( $mem_item ) ), $mem_item . ',183454,,,' . TCGiant_Sync_Category_Memory::now() );
list( $res, $calls ) = push( old_import( 209, $whole_graded ) );
$body = $calls[0][1] ?? '';
check( '  its category in the import\'s memory of it (183454): known, the condition sent, no category', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), exporter()->get_export_settings( 209 )['_source']['category'], value_of( $body, 'e:ConditionID' ), in_array( 'PrimaryCategory', (array) fields_of( $body ), true ) ), array( 'updated', 'live', '2750', false ) );

check( 'FIX 2b. the used caliper (3000) moved to 183454: refused - eBay takes only a card\'s condition there; nothing to read', refused_with( push( imported( 'part', 210, array( 'image_id' => $part_photos[0], 'stock' => 2 ), array( '_ebay_export_category_id' => '183454' ) ) ), 'Condition — eBay takes only a trading card\'s condition, Graded or Ungraded, in 183454 CCG Individual Cards, and this listing\'s is not one' ), array( 'missing_export_settings', array(), true ) );
check( '  a card condition of its own on the caliper, left in 33564: refused, as a new listing is', refused_with( push( imported( 'part', 211, array( 'image_id' => $part_photos[0], 'stock' => 2 ), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded', '_ebay_export_ungraded_condition' => '400010' ) ) ), 'eBay Category — eBay takes card grading and condition details only in 183454' ), array( 'missing_export_settings', array(), true ) );
check( '  the PSA 10 card moved to the coin parent 253: refused, as a new listing is', refused_with( push( imported( 'card', 212, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_category_id' => '253' ) ) ), 'eBay Category — Coins: US (253) is a parent category on eBay' ), array( 'missing_export_settings', array(), true ) );
check( '  revise_pair_problems(): none for a pushed product, a new listing, or a revise that sends neither', array( TCGiant_Sync_Exporter::revise_pair_problems( array( '_source' => array( 'category' => 'override', 'condition' => 'default' ), '_send_all' => true ) ), TCGiant_Sync_Exporter::revise_pair_problems( array( '_source' => array( 'category' => 'default', 'condition' => 'default' ), '_send_all' => false ) ) ), array( array(), array() ) );

$card_nopol = $card_live;
unset( $card_nopol['policies'] );
$nopol = array( '_ebay_live' => $card_nopol, '_ebay_export_fulfillment_policy' => '250000077', '_ebay_shipping_policy_id' => '', '_ebay_return_policy_id' => '', '_ebay_payment_policy_id' => '' );
$p     = imported( 'card', 213, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), $nopol );
check( 'FIX 5. its own shipping policy, the listing\'s return and payment policies not read: read first, and that read failing, refused - never sent the shop\'s', refused_with( push( $p ), 'Business Policies — the plugin has not read this listing\'s return and payment policies on eBay, and eBay changes a listing\'s business policies only with all three sent' ), array( 'missing_export_settings', array( 'GetItem' ), true ) );
check( '  built anyway (as Verify\'s builder would), no SellerProfiles at all', false !== strpos( private_method( 'TCGiant_Sync_Exporter', 'build_item_xml' )->invoke( exporter(), $p, exporter()->get_export_settings( 213 ), true ), '<SellerProfiles>' ), false );
update_post_meta( 213, '_ebay_return_policy_id', '240000012' );
check( '  the return policy kept by 3.27.8\'s import: the payment policy alone named, and the listing not read again within the hour', refused_with( push( $p ), 'has not read this listing\'s payment policy on eBay' ), array( 'missing_export_settings', array(), true ) );
update_post_meta( 213, '_ebay_payment_policy_id', '240000013' );
list( , $calls ) = push( $p );
check( '  both kept: sent with its own shipping policy, nothing read', array( names_of( $calls ), policies_of( $calls[0][1] ?? '' ) ), array( array( 'ReviseFixedPriceItem' ), array( '250000077', '240000012', '240000013' ) ) );
forget_failed_reads();
$p = imported( 'card', 221, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), $nopol );
$API->answers['GetItem'] = read_answer( '256100000001', array( 'SellerProfiles' => array( 'SellerShippingProfile' => array( 'ShippingProfileID' => '240000011' ), 'SellerReturnProfile' => array( 'ReturnProfileID' => '240000012' ), 'SellerPaymentProfile' => array( 'PaymentProfileID' => '240000013' ) ) ) );
list( $res, $calls ) = push( $p );
check( 'Q10. the read answering its three policies: its own shipping policy sent with eBay\'s return and payment', array( names_of( $calls ), policies_of( $calls[1][1] ?? '' ) ), array( array( 'GetItem', 'ReviseFixedPriceItem' ), array( '250000077', '240000012', '240000013' ) ) );
$p = imported( 'card', 222, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), $nopol );
$API->answers['GetItem'] = read_answer( '256100000001', array( 'ConditionID' => '2750' ) );
list( $res, $calls, $warned ) = push( $p );
$q11_says = 'Push: eBay listing 256100000001 of WC #222 doesn\'t use business policies, so the product\'s shipping policy was not applied. The Update went without it; the listing\'s shipping and returns are as they were on eBay.';
check( 'Q11. the read answering none (the listing does not use business policies): not refused - the Update goes without SellerProfiles, and says so', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), in_array( 'SellerProfiles', (array) fields_of( $calls[1][1] ?? '' ), true ), $warned ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), false, array( $q11_says ) ) );
$p->data['regular_price'] = '45.00';
list( $res, $calls, $warned ) = push( $p );
check( '  a price change later: no second read, the new price sent, the shipping policy still left out and said', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $calls[0][1] ?? '', 'e:StartPrice' ), ebays_own_in( $calls[0][1] ?? '' ), $warned ), array( 'updated', array( 'ReviseFixedPriceItem' ), '45.00', array(), array( $q11_says ) ) );
check( '  its settings: the product\'s own shipping policy is no change to send', array( exporter()->get_export_settings( 222 )['_source']['policies'], exporter()->get_export_settings( 222 )['_policies_ignored'] ?? null, TCGiant_Sync_Exporter::policies_unknown_problem( exporter()->get_export_settings( 222 ) ) ), array( 'default', array(), '' ) );
check( '  recorded with none of either, as GetItem leaves out what a listing has none of', array( TCGiant_Sync_Ebay_Live::snapshot( 222 )['policies'] ?? null, TCGiant_Sync_Ebay_Live::snapshot( 222 )['descriptors'] ?? null ), array( array(), array() ) );
$p = imported( 'card', 229, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), $nopol );
$API->answers['GetItem'] = read_answer( '256100000999', array( 'SellerProfiles' => array( 'SellerShippingProfile' => array( 'ShippingProfileID' => '1' ), 'SellerReturnProfile' => array( 'ReturnProfileID' => '2' ), 'SellerPaymentProfile' => array( 'PaymentProfileID' => '3' ) ) ) );
check( '  an answer about another listing is not this one\'s: refused, nothing recorded', array( refused_with( push( $p ), 'has not read this listing\'s return and payment policies' ), TCGiant_Sync_Ebay_Live::snapshot( 229 )['full'] ?? null ), array( array( 'missing_export_settings', array( 'GetItem' ), true ), null ) );
$p = old_import( 230, array( '_ebay_export_category_id' => '33564' ) );
$API->answers['GetItem'] = read_answer( '2561000230', array( 'PrimaryCategory' => array( 'CategoryID' => '20081' ) ) );
list( $res, $calls ) = push( $p );
check( '  a listing read whole that has no condition on eBay: the category of its own goes alone, for eBay to judge', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $calls[1][1] ?? '', 'e:PrimaryCategory/e:CategoryID' ), array_values( array_intersect( (array) fields_of( $calls[1][1] ?? '' ), $condition_fields ) ) ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), '33564', array() ) );
$p = old_import( 231, array( '_ebay_export_category_id' => '183454' ) );
update_post_meta( 231, '_ebay_listing_status', 'Ended' );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
$API->answers['GetItem']              = read_answer( '2561000231', array( 'ListingType' => 'FixedPriceItem', 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0', 'ListingStatus' => 'Completed' ) ) );
list( , $calls ) = push( $p );
check( '  a listing known to have ended: a new listing, so nothing read first', names_of( $calls )[0] ?? null, 'ReviseFixedPriceItem' );
unset( $API->answers['ReviseFixedPriceItem'] );
unset( $API->answers['GetItem'] );
check( '  live_unknown_groups(): none for a pushed product, a read listing, or a revise sending nothing of eBay\'s', array(
	TCGiant_Sync_Exporter::live_unknown_groups( array( '_source' => array( 'category' => 'default', 'condition' => 'override' ), '_send_all' => true ) ),
	TCGiant_Sync_Exporter::live_unknown_groups( array( '_source' => array( 'category' => 'default', 'condition' => 'override' ), '_send_all' => false, '_live_read' => true ) ),
	TCGiant_Sync_Exporter::live_unknown_groups( array( '_source' => array( 'category' => 'default', 'condition' => 'default' ), '_send_all' => false, '_policies_unknown' => array( 'return_policy_id' ) ) ),
	TCGiant_Sync_Exporter::live_unknown_groups( array( '_source' => array( 'category' => 'default', 'condition' => 'override', 'policies' => 'override' ), '_send_all' => false, '_policies_unknown' => array( 'return_policy_id' ) ) ),
), array( array(), array(), array(), array( 'category', 'policies' ) ) );

// Round 4: the read used fully. Ended: the ended path with its answer.
forget_failed_reads();
$ended_read = array( 'ListingType' => 'FixedPriceItem', 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '0' ), 'PrimaryCategory' => array( 'CategoryID' => '183454', 'CategoryName' => 'Toys & Hobbies:Collectible Card Games:CCG Individual Cards' ), 'ConditionID' => '2750', 'ConditionDescriptors' => array( 'ConditionDescriptor' => array( array( 'Name' => '27501', 'Value' => '275010' ), array( 'Name' => '27502', 'Value' => '275020' ) ) ) );
$API->answers['GetItem'] = read_answer( '2561000237', $ended_read );
list( $res, $calls ) = push( old_import( 237, array( '_ebay_export_category_id' => '183454' ) ) );
check( 'R4 2. the read finds the listing ended: no revise and no second GetItem - the new listing in its place, with eBay\'s condition', array( names_of( $calls ), $res['action'] ?? ( is_wp_error( $res ) ? $res->get_error_message() : null ), value_of( end( $calls )[1] ?? '', 'e:PrimaryCategory/e:CategoryID' ), value_of( end( $calls )[1] ?? '', 'e:ConditionID' ) ), array( array( 'GetItem', 'AddFixedPriceItem' ), 'relisted', '183454', '2750' ) );
// Another eBay site, while the shop imports only its own (spot check S2):
// a live copy is revised, as 3.28.6 revised it, on this push and the next,
// and its site recorded, as the import records it.
update_option( 'tcgiant_sync_ebay_settings', array( TCGiant_Sync_Sites::SETTING => TCGiant_Sync_Sites::PRIMARY, 'marketplace' => 'EBAY_US' ) );
$API->answers['GetItem'] = read_answer( '2561000238', array( 'Site' => 'UK' ) + $ended_read + array() );
$API->answers['GetItem']['Item']['SellingStatus'] = array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' );
list( $res, $calls ) = push( old_import( 238, array( '_ebay_export_category_id' => '183454' ) ) );
check( 'S2. the read finds it live on eBay UK, the shop importing eBay US only: its site recorded, and the Update sent, as 3.28.6 sent it', array( names_of( $calls ), is_array( $res ) ? $res['action'] : $res->get_error_code(), get_post_meta( 238, '_ebay_site', true ) ), array( array( 'GetItem', 'ReviseFixedPriceItem' ), 'updated', 'UK' ) );
list( $res, $calls ) = push( wc_get_product( 238 ) );
check( '  pushed again: not read again, and sent the same way', array( names_of( $calls ), is_array( $res ) ? $res['action'] : $res->get_error_code() ), array( array( 'ReviseFixedPriceItem' ), 'updated' ) );
// S3: ended on another site, no site recorded - the ended path with the
// read's answer: its sales brought in first, then refused for the site, as
// when a revise is refused as ended and the listing then read.
$API->answers['GetItem'] = read_answer( '2561000253', array( 'Site' => 'UK', 'Quantity' => '3', 'SellingStatus' => array( 'ListingStatus' => 'Completed', 'QuantitySold' => '2' ), 'ListingDetails' => array( 'EndTime' => '2026-10-30T10:00:00.000Z' ) ) + $ended_read );
$p = old_import( 253, array( '_ebay_export_category_id' => '183454' ) );
$p->data['stock'] = 3;
list( $res, $calls ) = push( $p );
check( 'S3. the read finds it ended on eBay UK, no site recorded: its sales brought in (3 listed, 2 sold), then refused for the site - no revise, no second GetItem', array( names_of( $calls ), is_wp_error( $res ) ? $res->get_error_code() : 'pushed', wc_get_product( 253 )->get_stock_quantity(), get_post_meta( 253, '_ebay_site', true ) ), array( array( 'GetItem' ), 'relist_refused', 1, 'UK' ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' );
list( $res, $calls ) = push( wc_get_product( 253 ) );
check( '  pushed again: not read again, and refused from the site recorded, as before', array( names_of( $calls ), is_wp_error( $res ) ? $res->get_error_code() : 'pushed' ), array( array( 'ReviseFixedPriceItem' ), 'relist_refused' ) );
unset( $API->answers['ReviseFixedPriceItem'] );
$API->answers['GetItem'] = read_answer( '2561000239', array( 'Site' => 'US' ) + $ended_read );
$API->answers['GetItem']['Item']['SellingStatus'] = array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' );
list( $res, $calls ) = push( old_import( 239, array( '_ebay_export_category_id' => '183454' ) ) );
check( '  on the shop\'s own site: recorded, and the Update sent', array( names_of( $calls ), get_post_meta( 239, '_ebay_site', true ) ), array( array( 'GetItem', 'ReviseFixedPriceItem' ), 'US' ) );
delete_option( 'tcgiant_sync_ebay_settings' );
unset( $API->answers['GetItem'] );
// Refused before any call for what no read changes: no read.
$p = old_import( 244, array( '_ebay_export_category_id' => '183454' ) );
$p->data['stock'] = 0;
list( $res, $calls ) = push( $p );
check( 'R4 2c. out of stock: refused for it, with no read', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', $calls ), array( 'out_of_stock', array() ) );
$keep_db         = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class() extends Template_World_DB {
	public function get_col( $query ) { return array( 999 ); }
};
list( $res, $calls ) = push( old_import( 245, array( '_ebay_export_category_id' => '183454' ) ) );
$GLOBALS['wpdb'] = $keep_db;
check( '  another product claiming the listing: refused for it, with no read', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', $calls ), array( 'shared_item_id', array() ) );
list( $res, $calls ) = push( old_import( 246, array( '_ebay_export_category_id' => '183454', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) ) );
check( '  a format change eBay will not make: refused for it, with no read', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', $calls ), array( 'format_change_needs_new_listing', array() ) );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/imported-revise-staging.php' ) . ' 2>&1', $tcg_staging );
$STAGING = json_decode( (string) end( $tcg_staging ), true );
check( '  a staging copy: no read (it sends nothing to eBay)', array( $STAGING['staging'] ?? null, in_array( 'GetItem', (array) ( $STAGING['calls'] ?? array( 'GetItem' ) ), true ) ), array( true, false ) );

// Q11's warning: said once eBay has taken the revise, and in words that fit.
forget_failed_reads();
$p = imported( 'card', 241, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), $nopol );
$API->answers['GetItem'] = read_answer( '256100000001', array( 'SellerProfiles' => array( 'SellerShippingProfile' => array( 'ShippingProfileID' => '240000011' ) ) ) );
list( $res, $calls, $warned ) = push( $p );
check( 'R4 5. read, the listing has only a shipping business policy: the product\'s not applied, and said in those words', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), $warned ), array( 'updated', array( 'Push: eBay listing 256100000001 of WC #241 has only a shipping business policy, and eBay changes business policies only all three together, so the product\'s shipping policy was not applied. The Update went without it; the listing\'s policies are as they were on eBay.' ) ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'Item.Title is too long.' );
list( $res, $calls, $warned ) = push( $p );
check( '  a revise eBay refuses: nothing said of the policy, as nothing was sent', array( is_wp_error( $res ), $warned ), array( true, array() ) );
unset( $API->answers['ReviseFixedPriceItem'], $API->answers['GetItem'] );
list( , $calls ) = push( imported( 'part', 242, array( 'image_id' => $part_photos[0], 'stock' => 2 ), array( '_ebay_listing_type' => 'StoresFixedPrice', '_tcgiant_best_offer_enabled' => '1' ) ) );
check( 'R4 3. an imported listing recorded as StoresFixedPrice: the product\'s own Best Offer goes, as for FixedPriceItem', in_array( 'BestOfferDetails', (array) fields_of( $calls[0][1] ?? '' ), true ), true );

// Spot check S1: the read for a partial condition of the product's own
// alone fails, or is held for the hour - the Update goes without that
// condition, and the log says so, not that it was not sent.
card_shop();
forget_failed_reads();
$API->answers['GetItem'] = new WP_Error( 'http_request_failed', 'timeout' );
$p = old_import( 251, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_ungraded_condition' => '400011' ) );
$p->data['regular_price'] = '33.00';
list( $res, $calls, $warned ) = push( $p );
check( 'S1. a card condition of its own with no Graded or Ungraded, the read failing: the price sent, no condition, and said in those words', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $calls[1][1] ?? '', 'e:StartPrice' ), array_values( array_intersect( (array) fields_of( $calls[1][1] ?? '' ), $condition_fields ) ), $warned ), array( 'updated', array( 'GetItem', 'ReviseFixedPriceItem' ), '33.00', array(), array( 'Push: could not read eBay\'s condition for listing 2561000251 of WC #251 (timeout), so the product\'s partial condition was not sent; the rest of the Update went. The listing is read again after the hour.' ) ) );
list( $res, $calls, $warned ) = push( $p );
check( '  pushed again within the hour: not read, the Update sent, and said', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), $warned ), array( 'updated', array( 'ReviseFixedPriceItem' ), array( 'Push: could not read eBay\'s condition for listing 2561000251 of WC #251 in the last hour, so the product\'s partial condition was not sent; the rest of the Update went. The listing is read again after the hour.' ) ) );
$API->answers['ReviseFixedPriceItem'] = new WP_Error( 'api_error', 'Item.Title is too long.' );
list( $res, $calls, $warned ) = push( $p );
check( '  a revise eBay refuses: nothing said of the condition, as nothing went', array( is_wp_error( $res ), $warned ), array( true, array() ) );
unset( $API->answers['ReviseFixedPriceItem'] );
forget_failed_reads();
$p = old_import( 252, array( '_ebay_export_item_type' => 'tcg', '_ebay_export_ungraded_condition' => '400011', '_ebay_export_fulfillment_policy' => '250000077' ) );
list( $res, $calls, $warned ) = push( $p );
check( '  with a shipping policy of its own too, whose others the read was for: refused, and "the Update was not sent" said, as it was not', array( is_wp_error( $res ) ? $res->get_error_code() : 'pushed', names_of( $calls ), $warned ), array( 'missing_export_settings', array( 'GetItem' ), array( 'Push: could not read eBay listing 2561000252 of WC #252 (timeout), so its condition, policies could not be checked, and the Update was not sent.' ) ) );
check( '  goes_without_read(): only for a partial condition alone', array( TCGiant_Sync_Exporter::goes_without_read( exporter()->get_export_settings( 251 ) ), TCGiant_Sync_Exporter::goes_without_read( exporter()->get_export_settings( 252 ) ) ), array( true, false ) );
unset( $API->answers['GetItem'] );
forget_failed_reads();
list( $res, , $warned ) = push( wc_get_product( 235 ) );
check( '  the next push, of a listing read before: nothing said of a partial condition', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), array_values( array_filter( $warned, function ( $w ) { return false !== strpos( $w, 'partial condition' ); } ) ) ), array( 'updated', array() ) );

// C: Step 1's Item Type alone, at odds with the condition eBay has, is no
// change: an Update of the title goes, and sends no condition.
$p = imported( 'card', 223, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ), 'name' => 'Charizard Holo PSA 10 - new title' ), array( '_ebay_export_item_type' => 'other' ) );
list( $res, $calls ) = push( $p );
check( 'FIX C. the PSA 10 card with a stray Item Type "All Other": its title Update goes, no condition, nothing read', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), names_of( $calls ), value_of( $calls[0][1] ?? '', 'e:Title' ), ebays_own_in( $calls[0][1] ?? '' ) ), array( 'updated', array( 'ReviseFixedPriceItem' ), 'Charizard Holo PSA 10 - new title', array() ) );
$s = exporter()->get_export_settings( 223 );
check( '  its settings hold eBay\'s graded card, not the stray type', array( $s['item_type'], $s['condition_type'], $s['grade_value'], $s['_source']['condition'] ), array( 'tcg', 'graded', '10', 'live' ) );
$s = exporter()->get_export_settings( imported( 'card', 227, array( 'image_id' => $card_photos[0] ), array( '_ebay_export_item_type' => 'other', '_ebay_export_ungraded_condition' => '400012' ) )->get_id() );
check( '  a stray type with a stray card condition: not whole, so the card condition is eBay\'s too', array( $s['item_type'], $s['ungraded_condition'], $s['_ungraded_from'] ), array( 'tcg', '', 'live' ) );
list( $res, $calls ) = push( imported( 'part', 224, array( 'image_id' => $part_photos[0], 'stock' => 2 ), array( '_ebay_export_item_type' => 'tcg' ) ) );
check( '  the caliper with a stray "Trading Cards": the same', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), ebays_own_in( $calls[0][1] ?? '' ) ), array( 'updated', array() ) );
list( $res, $calls ) = push( imported( 'card', 225, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_item_type' => 'tcg', '_ebay_export_condition_type' => 'ungraded' ) ) );
check( '  Ungraded with no card condition over the graded card: not whole, so neither sent nor refused', array( is_array( $res ) ? $res['action'] : $res->get_error_message(), ebays_own_in( $calls[0][1] ?? '' ) ), array( 'updated', array() ) );
check( '  a whole All Other condition of its own (1000) over the card in 183454: sent, so refused - the one case a condition of its own conflicts', refused_with( push( imported( 'card', 226, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ), array( '_ebay_export_item_type' => 'other', '_ebay_export_condition_id' => '1000' ) ) ), 'Condition — eBay takes only a trading card\'s condition, Graded or Ungraded, in 183454' ), array( 'missing_export_settings', array(), true ) );

TCGiant_Sync_OAuth::$settings['export_listing_type']     = 'Chinese';
TCGiant_Sync_OAuth::$settings['export_listing_duration'] = 'Days_7';
list( , $calls ) = push( imported( 'part', 228, array( 'image_id' => $part_photos[0], 'stock' => 3 ) ) );
check( 'FIX 3. an imported fixed-price listing, the default now an auction: its own quantity by its own format', value_of( $calls[0][1] ?? '', 'e:Quantity' ), '3' );
list( , $calls ) = push( imported( 'part', 233, array( 'image_id' => $part_photos[0], 'stock' => 3 ), array( '_ebay_listing_type' => 'StoresFixedPrice' ) ) );
check( '  one recorded as a format the push does not make (StoresFixedPrice): its own quantity, not the default auction\'s 1', value_of( $calls[0][1] ?? '', 'e:Quantity' ), '3' );
list( , $calls ) = push( imported( 'part', 214, array( 'image_id' => $part_photos[0], 'stock' => 3 ), array( '_ebay_listing_type' => '' ) ) );
$body = $calls[0][1] ?? '';
check( '  one whose format no read recorded: its price and its own quantity, no format', array( value_of( $body, 'e:StartPrice' ), value_of( $body, 'e:Quantity' ), array_values( array_intersect( (array) fields_of( $body ), array( 'ListingType', 'ListingDuration' ) ) ) ), array( '40.00', '3', array() ) );
card_shop();

$stored = array( 'item_id' => '5', 'condition_id' => '2750', 'descriptors' => array( array( 'name' => '27501', 'value' => '275010' ) ) );
check( 'FIX 7. merge(): a new condition with no descriptors drops the old ones; the same condition, new descriptors, or no condition, as before', array(
	array_key_exists( 'descriptors', TCGiant_Sync_Ebay_Live::merge( $stored, array( 'item_id' => '5', 'condition_id' => '3000' ), '5' ) ),
	TCGiant_Sync_Ebay_Live::merge( $stored, array( 'item_id' => '5', 'condition_id' => '2750' ), '5' )['descriptors'] ?? null,
	TCGiant_Sync_Ebay_Live::merge( $stored, array( 'item_id' => '5', 'condition_id' => '4000', 'descriptors' => array( array( 'name' => '40001', 'value' => '400010' ) ) ), '5' )['descriptors'] ?? null,
	array_key_exists( 'descriptors', TCGiant_Sync_Ebay_Live::merge( $stored, array( 'item_id' => '5', 'location' => 'Austin, TX' ), '5' ) ),
), array( false, $stored['descriptors'], array( array( 'name' => '40001', 'value' => '400010' ) ), true ) );
$s = with_live( 215, array( 'condition_id' => '3000' ) );
check( '  the graded card moved to 3000 on eBay then reads as All Other 3000, not a graded card', array( $s['item_type'], $s['condition_id'], $s['condition_type'] ), array( 'other', '3000', '' ) );

check( 'FIX 8. photos_match: the imported photos as they were', TCGiant_Sync_Ebay_Live::photos_match( $card_photos, $card_live ), true );
$GLOBALS['W']['meta'][ $card_photos[1] ]['_wp_attachment_backup_sizes'] = array( 'full-orig' => array( 'file' => 'photo-9102.jpg' ) );
check( '  one edited in WordPress\'s image editor since (its original kept): changed', TCGiant_Sync_Ebay_Live::photos_match( $card_photos, $card_live ), false );
$card = imported( 'card', 216, array( 'image_id' => $card_photos[0], 'gallery' => array( $card_photos[1] ) ) );
list( , $calls ) = push( $card );
check( '  so its Update sends the photos', ebays_own_in( $calls[0][1] ?? '' ), array( 'PictureDetails' ) );
unset( $GLOBALS['W']['meta'][ $card_photos[1] ]['_wp_attachment_backup_sizes'] );
$GLOBALS['W']['meta'][ $card_photos[1] ]['_wp_attached_file'] = '2026/10/photo-9102-e1730000000123.jpg';
check( '  one whose file the editor renamed: changed', TCGiant_Sync_Ebay_Live::photos_match( $card_photos, $card_live ), false );
unset( $GLOBALS['W']['meta'][ $card_photos[1] ]['_wp_attached_file'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CODE\n" . str_repeat( '=', 124 ) . "\n";

$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-importer.php' );
preg_match( '/private static function record_renewal\(.*?\n\t}\n/s', $src, $renewal );
check( 'the renewal light path neither reads nor writes eBay\'s values (it decides as 3.28.6 did)', array( '' !== ( $renewal[0] ?? '' ), false === stripos( $renewal[0] ?? '', '_ebay_live' ), false === strpos( $renewal[0] ?? '', 'Ebay_Live' ) ), array( true, true, true ) );
$exp = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
$adm = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
check( 'the Push button posts the box, ticked or not, and only when the tab shows it', 1 === substr_count( $adm, "override_follow_defaults:$('#_ebay_export_follow_defaults').length?($('#_ebay_export_follow_defaults').is(':checked')?'1':'0'):''," ), true );
check( 'the push of an imported auction relists through the guarded path, never RelistItem itself', array( 1 === preg_match( '/->relist_ended_listing\( \$product->get_id\(\), \'Push\', \$ended_item, \$this->own_content_xml \)/', $exp ), 0 === preg_match( '/function do_push.*?->relist_(fixed_price_)?item\(.*?function explain_ebay_error/s', $exp ) ), array( true, true ) );
$cron = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-cron.php' );
$uni  = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
check( 'the upgrade\'s pass runs from admin pages (behind its own gate) and the hourly check, and uninstall removes its record', array( false !== strpos( $cron, "add_action( 'admin_init', array( 'TCGiant_Sync_Ebay_Live', 'sweep_origins_from_admin' ) );" ), false !== strpos( $cron, "add_action( 'tcgiant_sync_check_ended_listings', array( 'TCGiant_Sync_Ebay_Live', 'sweep_origins' ), 5 );" ), false !== strpos( $uni, "'tcgiant_sync_origin_sweep'," ) ), array( true, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nADMIN: THE EBAY TAB AND ITS PUSH BUTTON\n" . str_repeat( '=', 124 ) . "\n";

exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/imported-revise-admin.php' ) . ' 2>&1', $tcg_admin, $tcg_admin_code );
$ADMIN = json_decode( (string) end( $tcg_admin ), true );
check( 'the tab and the button ran, in their own process', is_array( $ADMIN ), true );
check( 'ADMIN 29. an imported card in a shop with no export defaults: pre-validated and queued', array( $ADMIN['card']['ok'] ?? null, $ADMIN['card']['queued'] ?? null ), array( true, true ) );
check( '  the tab: category, condition and policies as they are on eBay, ready', array_slice( $ADMIN['card_lines'] ?? array(), 0, 4 ), array( '✔ Ready to push', '✔ Category: as it is on eBay - CCG Individual Cards (183454)', '✔ Condition: as it is on eBay', '✔ Business Policies: as they are on eBay' ) );
check( '  its box unticked; posting it unticked stores nothing, ticked stores 1', array( $ADMIN['card_box'] ?? null, $ADMIN['stored_unticked'] ?? null, $ADMIN['stored_ticked'] ?? null ), array( false, '', '1' ) );
check( '  a pushed product\'s box ticked; unticked stores 0, ticked nothing', array( $ADMIN['pushed_box'] ?? null, $ADMIN['pushed_unticked'] ?? null, $ADMIN['pushed_ticked'] ?? null ), array( true, '0', '' ) );
check( '  the product save keeps it the same way', array( $ADMIN['saved_unticked'] ?? null, $ADMIN['saved_ticked'] ?? null, $ADMIN['saved_absent'] ?? null ), array( '', '1', '1' ) );
check( '  a product with no listing: no box', $ADMIN['new_box'] ?? null, null );
check( '  set to send the shop\'s defaults, of which there are none: refused, as before', array( $ADMIN['card_defaults']['ok'] ?? null, false !== strpos( (string) ( $ADMIN['card_defaults']['message'] ?? '' ), 'missing required settings' ) ), array( false, true ) );
check( '  the readiness line for the shop\'s default card condition sent as its pair', $ADMIN['pair_line'] ?? null, '● Condition: Ungraded - Excellent - eBay\'s table of card conditions does not list the default for this category: sent as Lightly Played (Excellent)' );
check( 'Q10. a category of its own, the listing\'s condition not read: the tab says the push reads it first (a warning, not a tick), and the button queues it', array( $ADMIN['pair_lines'][0] ?? null, $ADMIN['pair_lines'][2] ?? null, $ADMIN['pair_press']['ok'] ?? null, $ADMIN['pair_press']['queued'] ?? null ), array( '✔ Ready to push', '● Condition: not read from eBay yet - the push reads this listing first, with one call, and checks it then', true, true ) );
check( '  a whole condition of its own, the category not read: the category line says the same, and the button queues it', array( $ADMIN['cat_unknown_line'] ?? null, $ADMIN['cat_unknown_press']['ok'] ?? null, $ADMIN['cat_unknown_press']['queued'] ?? null ), array( '● Category: not read from eBay yet - the push reads this listing first, with one call, and checks it then', true, true ) );
check( '  a pair that is known and conflicts (a part moved to 183454): not ready, in the push\'s words, and the button refuses', array( $ADMIN['conflict_line'] ?? null, $ADMIN['conflict_press']['ok'] ?? null ), array( '✘ Condition: eBay takes only a trading card\'s condition, Graded or Ungraded, in 183454 CCG Individual Cards, and this listing\'s is not one: set Item Type to Trading Cards and the card\'s condition on the product\'s eBay tab, or choose another category', false ) );
check( '  its own shipping policy beside two not read: the policies line says the same', $ADMIN['policies_line'] ?? null, '● Business Policies: not read from eBay yet - the push reads this listing first, with one call, and checks it then' );
check( 'Q11. read, and the listing does not use business policies: a warning, not a refusal, and the button queues the Update', array( $ADMIN['policies_read_line'] ?? null, $ADMIN['policies_read_press']['ok'] ?? null, $ADMIN['policies_read_press']['queued'] ?? null ), array( '● Business Policies: this listing doesn\'t use business policies, so the product\'s shipping policy is not applied - its shipping and returns stay as they are on eBay', true, true ) );
check( '  one with only some of the three: said so', $ADMIN['policies_some_line'] ?? null, '● Business Policies: this listing has only some of the three business policies, which eBay changes only together, so the product\'s shipping policy is not applied - its policies stay as they are on eBay' );
check( 'R4 1. part of a condition of its own, eBay\'s not read: the tab says the push reads it first', $ADMIN['partial_line'] ?? null, '● Condition: not read from eBay yet - the push reads this listing first, with one call, and checks it then' );
check( 'UNKNOWN 5. who made the listing not known: the tab says the next Update asks eBay first, and the box starts unticked', array( $ADMIN['untold_note'] ?? null, $ADMIN['untold_box'] ?? null ), array( 'Whether this listing was imported from eBay or pushed from here is not known yet: its next Update asks eBay first, with one call, when the listing started, and goes by that. Until then it is treated as imported.', false ) );
check( '  that read failed in the last hour: said', $ADMIN['untold_held_note'] ?? null, 'Whether this listing was imported from eBay or pushed from here is not known yet, and eBay could not be asked in the last hour: an Update until then sends only what you set here for this product. It is asked again at an Update after the hour.' );
check( '  the box saved as it was stores nothing, so the read still decides; ticked, the shop\'s defaults; a known origin says nothing', array( $ADMIN['untold_saved_unticked'] ?? null, $ADMIN['untold_saved_ticked'] ?? null, $ADMIN['known_note'] ?? null ), array( '', '1', false ) );
check( 'S1. the push\'s read of it failed in the last hour: the tab says the partial condition is not sent and the rest goes', $ADMIN['partial_held_line'] ?? null, '● Condition: eBay\'s condition could not be read in the last hour, so this product\'s partial condition is not sent - the rest of the Update goes, and the listing is read again after the hour' );
check( '  with a shipping policy of its own too: the partial condition not sent, and the push refused for the policies, as it is', array( $ADMIN['partial_pol_held_line'] ?? null, $ADMIN['partial_pol_held_policies'] ?? null ), array( '● Condition: eBay\'s condition could not be read in the last hour, so this product\'s partial condition is not sent; the listing is read again after the hour', '✘ Business Policies: the plugin has not read this listing\'s return and payment policies on eBay, and eBay changes a listing\'s business policies only with all three sent: run Fetch Inventory so the listing is read, or clear this product\'s own shipping policy to leave its policies as they are' ) );
check( '  a category of its own, the condition\'s read failed in the last hour: refused, in the push\'s words', $ADMIN['pair_held_line'] ?? null, '✘ Condition: the plugin has not read this listing\'s condition on eBay, and the category set on this product needs one it takes: set the whole condition on the product\'s eBay tab (Item Type, Graded or Ungraded, and the grade or the condition), or run Fetch Inventory so the listing is read' );
check( 'FIX 6d. linked by hand to a listing made on eBay, an old pushed version on the product: imported, and eBay\'s values recorded from the answer', $ADMIN['link_import'] ?? null, array( 'ok' => true, 'origin' => 'import', 'live' => '33564/3000' ) );
check( '  Link saying it wrote that', $ADMIN['link_by'] ?? null, 'link' );
check( '  linked by hand to the listing its own push made (its push mark): pushed', array( $ADMIN['has_mark'] ?? null, $ADMIN['link_push']['origin'] ?? null ), array( true, 'push' ) );

done();
