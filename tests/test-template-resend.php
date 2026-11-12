<?php
/**
 * Harness: Re-send to eBay (3.34.0) - a template-side change sent to the
 * listings already on eBay, description only.
 *
 * Design section 8.1's test-template-resend.php, held to the owner's
 * decisions: re-sends count against the bulk-push limit; Test on 5 before
 * Re-send all, for the template as it is; auctions left out; fixed-price
 * listings with sales in; batches of 50, a twelfth of the day's limit in
 * any two hours, and pauses for the budget; and the group of listings
 * going back to the plain description. And fix round 1's (the review of
 * dac1f22): variation listings and listings never pushed from this site
 * left out, the title held against eBay's, eBay's warnings shown, the
 * host's time, a run's ID, and the rest of its list.
 *
 * Loaded for real: the re-send, the exporter's description and the record
 * it keeps, the templates and their render, the API's cache and budget
 * figures, the listing link's lookup, the bulk-push limit, the Listing
 * Templates handlers, the Settings screen's Fetch eBay Policies and the
 * Emergency Stop, and the box's view. eBay, Action Scheduler and the
 * database's own rows are played (tests/support/resend-world.php).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/resend-world.php';

class Harness_Stop extends Exception {
	public $kind;
	public $data;
	public function __construct( $kind, $data = null ) { parent::__construct( $kind ); $this->kind = $kind; $this->data = $data; }
}
$GLOBALS['nonce_ok'] = true;
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) {
	if ( empty( $GLOBALS['nonce_ok'] ) ) {
		throw new Harness_Stop( 'died', 'nonce' );
	}
	$GLOBALS['nonce_action'] = $action;
	return 1;
}
function check_ajax_referer( $action = -1, $arg = false, $die = true ) { return check_admin_referer( $action ); }
function wp_die( $message = '' ) { throw new Harness_Stop( 'died', $message ); }
function wp_safe_redirect( $url ) { throw new Harness_Stop( 'redirect', $url ); }
function wp_send_json_success( $data = null ) { throw new Harness_Stop( 'json_ok', $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Stop( 'json_error', $data ); }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">'; }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function get_terms( $args = array() ) { return array(); }

class TCGiant_Sync_Inventory {
	const SETTLE_KEYS         = array();
	const AUCTION_FIGURE_KEYS = array();
	public static function __callStatic( $name, $args ) { return null; }
}
/** The Emergency Stop's other half, played: the import's queues. */
class TCGiant_Sync_Importer {
	public static $stopped = 0;
	public static function stop_all() { self::$stopped++; }
	public static function update_sync_state( $updates ) {}
}
require dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php';

/** A handler, run to its end: what stopped it, and the notice it left. */
function handler( $callback, array $post = array() ) {
	$_POST    = $post;
	$_REQUEST = $post;
	$stop     = null;
	try {
		call_user_func( $callback );
	} catch ( Harness_Stop $e ) {
		$stop = $e;
	}
	$notice = get_transient( 'tcgiant_tpl_notice_1' );
	delete_transient( 'tcgiant_tpl_notice_1' );
	return array( $stop ? $stop->kind : '', $stop ? $stop->data : null, is_array( $notice ) ? $notice : null );
}
function admin_object( $class ) {
	return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
}

/** Back to a shop with templates and nothing else: no products, no counts, nothing queued. */
function fresh() {
	foreach ( array_keys( $GLOBALS['W']['products'] ) as $id ) {
		unset( $GLOBALS['W']['meta'][ $id ], $GLOBALS['W']['posts'][ $id ] );
	}
	$GLOBALS['W']['products'] = array();
	foreach ( array_keys( $GLOBALS['W']['options'] ) as $name ) {
		if ( 0 === strpos( $name, 'tcgiant_sync_tpl_resend' ) || 0 === strpos( $name, 'tcgiant_sync_bulk' ) || 0 === strpos( $name, 'tcgiant_sync_api_calls_' ) ) {
			unset( $GLOBALS['W']['options'][ $name ] );
		}
	}
	unset( $GLOBALS['W']['item_index'] );
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['log']        = array();
	$GLOBALS['AS']['actions']   = array();
	$GLOBALS['wpdb']->rows      = array();
	$GLOBALS['API']->calls      = array();
	$GLOBALS['API']->answers    = array();
	TCGiant_Sync_DB::$rows      = array();
	$settings                        = TCGiant_Sync_Templates::default_settings();
	$settings['default_template_id'] = $GLOBALS['T'];
	update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
	roomy();
}

/** The log lines since the last asked, cleared. */
function said() {
	$lines              = array_map( function ( $l ) { return $l[1]; }, $GLOBALS['W']['log'] );
	$GLOBALS['W']['log'] = array();
	return implode( "\n", $lines );
}

/** The meta of every product, to show nothing was written. */
function products_meta() {
	$out = array();
	foreach ( array_keys( $GLOBALS['W']['products'] ) as $id ) {
		$out[ $id ] = $GLOBALS['W']['meta'][ $id ] ?? array();
	}
	return $out;
}

/** eBay's refusal of a listing, as trading_api_request() hands one back: its Errors in the data. */
function ebay_refused( $message, $code = '21916250' ) {
	return new WP_Error( 'api_error', $message, array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => $code, 'ShortMessage' => $message, 'LongMessage' => $message, 'SeverityCode' => 'Error' ) ) );
}

/** A Re-send all's figures, read back. */
function run_state() {
	return TCGiant_Sync_Template_Resend::state();
}

/** Run Re-send all's due batches. */
function run_due() {
	return as_run( TCGiant_Sync_Template_Resend::RUN_HOOK );
}

/** Run Re-send all's next batch, whenever it is due. */
function run_next() {
	return as_run( TCGiant_Sync_Template_Resend::RUN_HOOK, true, 1 );
}

$GLOBALS['T'] = make_template( array( 'name' => 'Clean', 'html' => '<h1>{{title}}</h1>{{{description}}}' ) );
$T            = $GLOBALS['T'];
fresh();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CONTENT HASH: THE PRODUCT'S FACTS, NOTHING THE TEMPLATE CHOOSES\n" . str_repeat( '=', 124 ) . "\n";

// What 3.33.0 recorded was never the same twice: its wrapper's class is new on
// every render. Now it is the facts the exporter hands render() that reach a
// field of the listing besides its description, and the price eBay is sent:
// nothing that reaches only the description, and no template-side setting.
$p    = listing( 501, array( 'name' => 'Charizard 4/102', 'description' => 'Holo. [x]' ), array( 'colour' => 'red' ) );
$one  = described( $p, array( 'template_id' => $T ) );
$two  = described( $p, array( 'template_id' => $T ) );
check( 'rendered twice: the same content hash', $one['content_hash'] === $two['content_hash'], true );
check( '  marked as made the 3.34.0 way', 0 === strpos( $one['content_hash'], TCGiant_Sync_Templates::CONTENT_HASH_PREFIX ) && 42 === strlen( $one['content_hash'] ), true );

$other = make_template( array( 'name' => 'Other', 'html' => '<div>{{{description}}}</div><p>{{sku}}</p>', 'content_filters' => 'all', 'design' => array( 'accent' => '#ff0000' ) ) );
check( 'another template: the same content hash', described( $p, array( 'template_id' => $other ) )['content_hash'], $one['content_hash'] );
TCGiant_Sync_Templates::save( $other, array( 'html' => '<section>{{{description}}}{{{specifics_table}}}</section>', 'design' => array( 'accent' => '#00ff00', 'background' => '#000000' ) ) );
check( '  saved again, new colours and a dark page: the same', described( $p, array( 'template_id' => $other ) )['content_hash'], $one['content_hash'] );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array_merge( TCGiant_Sync_Templates::settings(), array( 'shop_name' => 'Card Corner', 'ebay_store_url' => 'https://www.ebay.com/str/cards' ) ) );
check( '  the shop\'s own fields changed: the same', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );

$p->data['name'] = 'Charizard 4/102 Holo';
check( 'the product\'s title edited: a new content hash', described( $p, array( 'template_id' => $T ) )['content_hash'] !== $one['content_hash'], true );
$p->data['name'] = 'Charizard 4/102';
check( '  put back: the first again', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );
$p->data['regular_price'] = '12.00';
check( 'its price edited, which eBay is sent: a new content hash', described( $p, array( 'template_id' => $T ) )['content_hash'] !== $one['content_hash'], true );
$p->data['regular_price'] = '10.00';
$GLOBALS['W']['meta'][501][ TCGiant_Sync_Ebay_Price::OWN_META ] = '14.00';
check( '  an eBay price of its own: a new content hash', described( $p, array( 'template_id' => $T ) )['content_hash'] !== $one['content_hash'], true );
unset( $GLOBALS['W']['meta'][501][ TCGiant_Sync_Ebay_Price::OWN_META ] );
$p->data['sku'] = 'CHZ-4';
check( 'its SKU edited: a new content hash', described( $p, array( 'template_id' => $T ) )['content_hash'] !== $one['content_hash'], true );
$p->data['sku'] = '';
$p->data['description'] = 'Holo. Light play.';
check( 'its description edited, which reaches only the description: the same', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );
$p->data['short_description'] = 'A short line.';
check( '  its short description too: the same', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );
$p->data['description']       = 'Holo. [x]';
$p->data['short_description'] = '';
$GLOBALS['W']['meta'][501]['colour'] = 'blue';
check( 'product meta no template may show, changed: the same', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array_merge( TCGiant_Sync_Templates::settings(), array( 'meta_allowlist' => array( 'colour' ) ) ) );
check( 'a key added to the meta allowlist of Template settings, a template-side setting: the same', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );
$GLOBALS['W']['meta'][501]['colour'] = 'green';
check( '  the meta it allows, changed, which reaches only the description: the same', described( $p, array( 'template_id' => $T ) )['content_hash'], $one['content_hash'] );
$plain = described( $p, array( 'template_id' => 0 ) );
check( 'the plain description\'s content hash is as before: sha1 of the html', $plain['content_hash'], sha1( $plain['html'] ) );
check( '  and it is never a design inside a design', $plain['nested'], false );
fresh();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE COUNT: WHICH LISTINGS WOULD CHANGE, AND WHY THE OTHERS ARE LEFT OUT\n" . str_repeat( '=', 124 ) . "\n";

// A shop that pushed everything with the plain description an hour ago, and
// has just made Clean its default.
$shop = array(
	601 => listing( 601 ),
	602 => listing( 602, array(), array( '_ebay_item_id' => null ) ),
	603 => listing( 603, array(), array( '_ebay_listing_status' => 'Ended' ) ),
	604 => listing( 604, array(), array( '_ebay_listing_type' => 'Chinese' ) ),
	605 => listing( 605, array(), array( '_ebay_site' => 'Germany' ) ),
	606 => listing( 606, array(), array( '_ebay_site' => 'eBayMotors' ) ),
	607 => listing( 607, array(), array( '_ebay_site' => null ) ),
	608 => listing( 608, array(), array( '_ebay_item_id' => '119999999999' ) ),
	609 => listing( 609, array(), array( '_ebay_item_id' => '119999999999' ) ),
	610 => listing( 610 ),
	611 => listing( 611 ),
	612 => listing( 612, array(), array( '_tcgiant_desc_hash' => 'old', '_tcgiant_tpl_item' => '110000000612', '_tcgiant_desc_sent_at' => (string) ( time() - 3600 ) ) ),
	613 => listing( 613, array( 'description' => str_repeat( 'x', 500001 ) ) ),
	614 => listing( 614, array( 'description' => '<style>.wpl{color:red}</style><p>A WP-Lister listing.</p>' ) ),
	615 => listing( 615, array(), array( '_ebay_export_listing_type' => 'Chinese' ) ),
	616 => listing( 616, array( 'modified' => time() - 60 ) ),
	617 => listing( 617 ),
	618 => listing( 618 ),
	619 => listing( 619, array(), array( '_tcgiant_tpl_override' => 'none' ) ),
	620 => listing( 620, array(), array( '_tcgiant_tpl_override' => 'none' ) ),
	621 => new WC_Product_Variable( 621, array( 'name' => 'Sleeves', 'description' => 'Sleeves in three colours.', 'type' => 'variable', 'modified' => time() - 3700 ) ),
	622 => listing( 622, array( 'stock' => 0 ) ),
	623 => listing( 623, array(), array( '_ebay_listing_type' => 'StoresFixedPrice' ) ),
	624 => listing( 624, array( 'status' => 'trash' ) ),
	625 => listing( 625, array(), array( '_ebay_export_last_pushed' => null ) ),
	626 => listing( 626, array(), array( '_ebay_export_last_pushed' => null ) ),
);
foreach ( array( '_ebay_item_id' => '110000000621', '_ebay_listing_status' => 'Active', '_ebay_listing_type' => 'FixedPriceItem', '_ebay_site' => 'US', '_ebay_export_last_pushed' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) as $k => $v ) {
	$GLOBALS['W']['meta'][621][ $k ] = $v;
}
// 610 needs attention for its own listing; 611 for a listing it no longer has.
$GLOBALS['W']['meta'][610][ TCGiant_Sync_Exporter::RESEND_FAILED_META ] = array( 'item' => '110000000610', 'tries' => 4, 'day' => gmdate( 'Y-m-d', time() - 86400 ), 'message' => 'No.', 'attention' => true );
$GLOBALS['W']['meta'][611][ TCGiant_Sync_Exporter::RESEND_FAILED_META ] = array( 'item' => '110000000999', 'tries' => 4, 'day' => gmdate( 'Y-m-d', time() - 86400 ), 'message' => 'No.', 'attention' => true );
// 617's description comes out new every time: a shortcode with a counter.
add_filter( 'tcgiant_sync_ebay_description', function ( $html, $product ) {
	static $n = 0;
	return 617 === $product->get_id() ? $html . '<p>Visitor ' . ( ++$n ) . '</p>' : $html;
}, 10, 2 );
// 618 was sent Clean already; 620 was sent Clean and is now set to None.
sent_now( $shop[618] );
$GLOBALS['W']['meta'][620]['_tcgiant_tpl_override'] = '';
sent_now( $shop[620] );
$GLOBALS['W']['meta'][620]['_tcgiant_tpl_override'] = 'none';
// 625 and 626 were only ever imported: 625's product untouched since its
// last sync, 626's saved after. Neither was ever pushed from here: held.
TCGiant_Sync_DB::$rows[625]['last_synced'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
TCGiant_Sync_DB::$rows[626]['last_synced'] = gmdate( 'Y-m-d H:i:s', time() - 7200 );
$shop[626]->data['modified'] = time() - 3600;
// 621, a variation listing, is left to its next push; so is 627, whose
// title on eBay is not the website's, and 628, whose Listings row has none.
$shop[627] = listing( 627 );
TCGiant_Sync_DB::$rows[627]['ebay_title'] = 'Card 627 - edited in Seller Hub';
$shop[628] = listing( 628 );
TCGiant_Sync_DB::$rows[628]['ebay_title'] = '';

$before = products_meta();
rs_calls();
$done   = count_now();
check( 'the count asked eBay nothing', rs_calls(), array() );
check( '  and wrote nothing to any product', products_meta() === $before, true );
check( 'the groups: Clean, and back to the plain description', array_keys( $done['groups'] ), array( 't' . $T, 'none' ) );
check( '  Clean: 601, 611, 622 and 623; one at quantity 0', array( $done['groups'][ 't' . $T ]['count'], $done['groups'][ 't' . $T ]['last'], $done['groups'][ 't' . $T ]['version'], $done['groups'][ 't' . $T ]['template_id'] ), array( 4, 1, 1, $T ) );
check( '  the plain description: 620', $done['groups']['none']['count'], 1 );
ksort( $done['reasons'] );
check( 'left out, each with its reason', $done['reasons'], array(
	'attention'     => 1,
	'auction'       => 1,
	'ended'         => 1,
	'held'          => 1,
	'nested'        => 1,
	'never_pushed'  => 2,
	'other_site'    => 2,
	'recent'        => 1,
	'refused'       => 1,
	'shared'        => 2,
	'site_unknown'  => 1,
	'title_differs' => 2,
	'too_long'      => 1,
	'unlinked'      => 1,
	'unstable'      => 1,
	'variable'      => 1,
) );
check( '  618, sent Clean already, unchanged: costs nothing', $done['unchanged'], 1 );
check( '  619, never sent a template and still None: not counted at all; nor 624, in the trash', $done['looked'], 26 );
$members = array();
for ( $n = 0; $n < $done['chunks']; $n++ ) {
	$members += (array) ( get_option( TCGiant_Sync_Template_Resend::IDS_PREFIX . $done['id'] . '_' . $n )[ 't' . $T ] ?? array() );
}
ksort( $members );
check( 'the listings kept, by group, quantity 0 marked', $members, array( 601 => 0, 611 => 0, 622 => 1, 623 => 0 ) );
check( 'one line in the log, which says nothing went to eBay', 1 === preg_match( '/^Re-send to eBay: counted 26 listing\(s\) a template reaches\. 5 would change .* 20 left out .* 1 unchanged\. Nothing was sent to eBay\.$/', said() ), true );

$v = TCGiant_Sync_Template_Resend::judge( 621 );
check( 'a variation listing is left to its next push: eBay does not say what a revise without its variations does', array( $v['verdict'], $v['reason'] ), array( 'left', 'variable' ) );
TCGiant_Sync_DB::$rows[627]['ebay_title'] = 'Card&nbsp;627 ';
check( 'the title on eBay, its entities and spaces read as eBay keeps them: the same, so not held for it', TCGiant_Sync_Template_Resend::judge( 627 )['verdict'], 'send' );
$v = TCGiant_Sync_Template_Resend::judge( 623 );
check( 'a listing eBay calls StoresFixedPrice goes through ReviseItem, as the push would', array( $v['verdict'], $v['fixed'] ), array( 'send', false ) );

// What a push refuses before eBay, each: its settings, a price below the
// lowest sent, eBay's required item specifics; and an auction known only by
// the format the product would be pushed as.
listing( 632, array(), array( '_ebay_export_category_id' => '261328', '_ebay_export_item_type' => 'coins' ) );
check( 'refused as a push would: a coin in a card category (its settings)', TCGiant_Sync_Template_Resend::judge( 632 )['reason'], 'refused' );
listing( 633, array(), array( '_ebay_export_price' => '0.50' ) );
check( '  an eBay price of its own below the lowest the plugin sends', TCGiant_Sync_Template_Resend::judge( 633 )['reason'], 'refused' );
listing( 634 );
$GLOBALS['API']->aspects = array( array( 'name' => 'Grade', 'required' => true, 'multi' => false, 'mode' => 'FREE_TEXT', 'selection' => '', 'values' => array() ) );
check( '  an item specific eBay requires, missing', TCGiant_Sync_Template_Resend::judge( 634 )['reason'], 'refused' );
$GLOBALS['API']->aspects = array();
check( '  and with it there: goes', TCGiant_Sync_Template_Resend::judge( 634 )['verdict'], 'send' );
// 3.38.0 (review 5's rev5:V01 and R5-1): a category requiring Brand, the
// Brand field, no other item specifics - judged as the push's Update, which
// sends no ItemSpecifics: left as the push refuses it with nothing recorded,
// and sent where the listing is recorded with that Brand (eBay keeps it).
listing( 6391, array(), array( TCGiant_Sync_Listing_Options::BRAND_META => 'Zeta' ) );
listing( 6392, array(), array( TCGiant_Sync_Listing_Options::BRAND_META => 'Zeta', TCGiant_Sync_Listing_Options::SENT_META => array( 'item' => '110000006392', 'specifics' => array( 'Brand' => 'Zeta' ) ) ) );
$GLOBALS['API']->aspects = array( array( 'name' => 'Brand', 'required' => true, 'multi' => false, 'mode' => 'FREE_TEXT', 'selection' => '', 'values' => array() ) );
check( '  Brand required, the Brand field and no other item specifics, nothing recorded: refused, as the push\'s Update is', TCGiant_Sync_Template_Resend::judge( 6391 )['reason'], 'refused' );
check( '  recorded with that Brand: goes, as the push\'s Update does', TCGiant_Sync_Template_Resend::judge( 6392 )['verdict'], 'send' );
$GLOBALS['API']->aspects = array();
listing( 635, array(), array( '_ebay_listing_type' => null, '_ebay_export_listing_type' => 'Chinese' ) );
check( 'no format recorded, the product pushed as an auction: an auction', TCGiant_Sync_Template_Resend::judge( 635 )['reason'], 'auction' );
$var = new WC_Product_Variable( 636, array( 'name' => 'Toploaders', 'description' => 'Packs of 25.', 'type' => 'variable', 'modified' => time() - 3700 ) );
foreach ( array( '_ebay_item_id' => '110000000636', '_ebay_listing_status' => 'Active', '_ebay_site' => 'US', '_ebay_export_listing_type' => 'Chinese', '_ebay_export_last_pushed' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) as $k => $v ) {
	$GLOBALS['W']['meta'][636][ $k ] = $v;
}
check( '  a variation listing asks to be pushed as an auction: left out as a variation listing, before anything else is asked', TCGiant_Sync_Template_Resend::judge( 636 )['reason'], 'variable' );
listing( 637, array(), array( '_ebay_export_listing_type' => 'Chinese', '_ebay_export_listing_duration' => 'Days_7' ) );
check( 'a live fixed-price listing the product now asks to be an auction: refused, as a push refuses it', TCGiant_Sync_Template_Resend::judge( 637 )['reason'], 'refused' );
new WC_Product_Variation( 638, array( 'name' => 'Sleeves - Red', 'type' => 'variation' ) );
$GLOBALS['W']['meta'][638] = array( '_ebay_item_id' => '110000000638', '_tcgiant_tpl_id' => (string) $T );
check( 'a variation on its own: not a listing, not counted', TCGiant_Sync_Template_Resend::judge( 638 )['verdict'], 'skip' );
fresh();
listing( 631, array( 'name' => str_repeat( 'Pikachu Illustrator ', 6 ) ) );
said();
$done = count_now();
check( 'a title past eBay\'s 80 characters: counted, without the push\'s line about cutting it', array( $done['groups'][ 't' . $T ]['count'], substr_count( said(), "\n" ) ), array( 1, 0 ) );
// A listing a push made whose site is not recorded - pushed before 3.27.5, on
// a shop importing from all eBay sites - is never taken for the shop's own:
// the shop may have changed its marketplace since. The box says what records
// it; once a sync has, it goes.
listing( 639, array(), array( '_ebay_site' => null, '_ebay_origin' => 'push', '_ebay_origin_by' => 'push' ) );
check( 'a listing a push made, its site not recorded: left out, never taken for the shop\'s own', array( TCGiant_Sync_Template_Resend::judge( 639 )['reason'], TCGiant_Sync_Template_Resend::judge( 639, true )['reason'] ), array( 'site_unknown', 'site_unknown' ) );
check( '  the box says what records it, and what to press then', TCGiant_Sync_Template_Resend::reason_words( 'site_unknown' ), 'Their eBay site is not recorded yet. The weekly full sync records it, or Fetch Inventory on the Import from eBay page does now; then press Count again.' );
TCGiant_Sync_Sites::record_site( 639, 'US' );
check( '  its site recorded by a sync: it goes', TCGiant_Sync_Template_Resend::judge( 639, true )['verdict'], 'send' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nASKING EBAY NOTHING: THE ITEM SPECIFICS FROM THIS SITE'S CACHE ONLY\n" . str_repeat( '=', 124 ) . "\n";

/** The real API's look-ups, with eBay's REST and Trading calls recorded rather than made. */
class Recording_API extends TCGiant_Sync_API {
	public $rest  = array();
	public $calls = array();
	public $answers = array();
	public $policies = array();
	public function request( $endpoint, $method = 'GET', $body = array(), $params = array(), $log_errors = true ) {
		$this->rest[] = $endpoint;
		return array( 'aspects' => array( array( 'localizedAspectName' => 'Card Name', 'aspectConstraint' => array( 'aspectRequired' => false ) ) ) );
	}
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->calls[] = array( $call_name, $xml_body );
		return array( 'Ack' => 'Success' );
	}
}
$api_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$api_slot->setAccessible( true );
}
$recording = new Recording_API();
$api_slot->setValue( null, $recording );

$specifics = make_template( array( 'name' => 'Specifics', 'html' => '{{{description}}}{{{specifics_table}}}{{{policy.returns}}}' ) );
fresh();
$s = listing( 701, array( 'attributes' => array( new WC_Product_Attribute( 'card name', array( 'Pikachu' ) ) ) ), array( '_tcgiant_tpl_override' => (string) $specifics ) );
delete_transient( 'tcgiant_aspects_0_29223' );
$missed = 0;
$plainly = $recording->cached_only( function () use ( $s ) {
	return exporter()->resend_description( $s, exporter()->resend_settings( $s ) );
}, $missed );
check( 'inside cached_only(), nothing kept: eBay is not asked', array( $recording->rest, $missed ), array( array(), 1 ) );
check( '  and the specifics keep the names the product gives them, as when eBay does not answer', false !== strpos( $plainly['html'], 'card name' ), true );
count_now();
check( 'the count, with nothing kept: no REST call and no Trading call', array( $recording->rest, $recording->calls ), array( array(), array() ) );
check( '  it counted the look-up it could not make', rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done']['missed'] >= 1, true );
check( '  and left it out until they are kept, in no group', array( rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done']['reasons'], rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done']['groups'] ), array( array( 'cache_cold' => 1 ), array() ) );
$v = TCGiant_Sync_Template_Resend::judge( 701, true );
check( '  just before a send, the same: it waits, and nothing is sent', array( $v['verdict'], $v['reason'], $recording->calls ), array( 'left', 'cache_cold', array() ) );
$n = listing( 702, array(), array( '_tcgiant_tpl_override' => 'none', '_tcgiant_tpl_id' => (string) $specifics ) );
$v = TCGiant_Sync_Template_Resend::judge( 702, true );
check( 'going back to the plain description, with the item specifics eBay requires not kept to check: it waits too', array( $v['verdict'], $v['reason'], $recording->rest ), array( 'left', 'cache_cold', array() ) );
exporter()->resend_description( $s, exporter()->resend_settings( $s ) );
check( 'outside it, as a push asks: one Taxonomy read, kept a week', array( count( $recording->rest ), $GLOBALS['W']['expiry']['tcgiant_aspects_0_29223'] ?? 0 ), array( 1, 7 * DAY_IN_SECONDS ) );
$recording->rest = array();
$kept            = $recording->cached_only( function () use ( $s ) {
	return exporter()->resend_description( $s, exporter()->resend_settings( $s ) );
}, $missed );
check( 'inside it, once kept: read from the cache, eBay\'s own name, still no call', array( $recording->rest, $missed, false !== strpos( $kept['html'], 'Card Name' ) ), array( array(), 0, true ) );
check( 'the returns block reads the policy cache, never eBay', array( $recording->rest, $recording->calls ), array( array(), array() ) );
check( 'once they are kept: it goes', TCGiant_Sync_Template_Resend::judge( 701, true )['verdict'], 'send' );

// A listing sent with a 3: hash, whose category's item specifics have since
// gone from the cache: its render cannot be compared with what was sent, so
// it reads cache_cold, never changed - whether it would change or not.
$h = listing( 703, array( 'attributes' => array( new WC_Product_Attribute( 'card name', array( 'Pikachu' ) ) ) ), array( '_tcgiant_tpl_override' => (string) $specifics ) );
sent_now( $h );
check( 'a listing sent with the cache warm, a 3: hash recorded: unchanged', array( TCGiant_Sync_Template_Resend::judge( 703, true )['verdict'], substr( (string) $GLOBALS['W']['meta'][703]['_tcgiant_content_hash'], 0, 2 ) ), array( 'unchanged', '3:' ) );
delete_transient( 'tcgiant_aspects_0_29223' );
$v = TCGiant_Sync_Template_Resend::judge( 703 );
check( '  the cache gone cold since: cache_cold, not changed, in the count and just before a send', array( $v['verdict'], $v['reason'], TCGiant_Sync_Template_Resend::judge( 703, true )['reason'] ), array( 'left', 'cache_cold', 'cache_cold' ) );
TCGiant_Sync_Templates::save( $specifics, array( 'html' => '<div>{{{description}}}</div>{{{specifics_table}}}' ) );
check( '  its template saved, so it would change: cache_cold too, not changed', TCGiant_Sync_Template_Resend::judge( 703 )['reason'], 'cache_cold' );
exporter()->resend_description( $h, exporter()->resend_settings( $h ) );
$v = TCGiant_Sync_Template_Resend::judge( 703, true );
check( '  the cache warm again: it goes, in its template\'s group', array( $v['verdict'], $v['group'] ), array( 'send', 't' . $specifics ) );
$api_slot->setValue( null, $GLOBALS['API'] );
fresh();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SEND: ONE CALL, THE ITEMID AND THE DESCRIPTION, AND THE RECORD A PUSH KEEPS\n" . str_repeat( '=', 124 ) . "\n";

$a = listing( 801 );
$b = listing( 802 );
$c = listing( 803, array(), array( '_ebay_listing_type' => 'StoresFixedPrice' ) );
$z = listing( 804, array( 'stock' => 0 ) );
$GLOBALS['W']['meta'][802]['_ebay_export_pushed_version'] = '3.33.0';
count_now();
$pushed_before = $GLOBALS['W']['meta'][802]['_ebay_export_last_pushed'];
$modified      = $b->data['modified'];
rs_calls();
$result = TCGiant_Sync_Template_Resend::test( 't' . $T );
$calls  = rs_calls();
check( 'Test on 5 sent the four there are, quantity 0 last', array_map( function ( $r ) { return $r['product_id']; }, $result['rows'] ), array( 801, 802, 803, 804 ) );
check( '  each through the call the push would use', array_map( function ( $c ) { return $c[0]; }, $calls ), array( 'ReviseFixedPriceItem', 'ReviseFixedPriceItem', 'ReviseItem', 'ReviseFixedPriceItem' ) );
$render = exporter()->resend_description( $b, exporter()->resend_settings( $b ) );
check( 'the body: the ItemID and the Description, nothing else', $calls[1][1], "<ErrorLanguage>en_US</ErrorLanguage>\n<WarningLevel>High</WarningLevel>\n<Item>\n<ItemID>110000000802</ItemID>\n<Description><![CDATA[" . $render['html'] . "]]></Description>\n</Item>" );
check( '  no Title, price, quantity, specifics, photos or category in any', preg_match( '/<(Title|StartPrice|Quantity|ItemSpecifics|PictureDetails|PrimaryCategory|Variations|SellerProfiles|ConditionID|SKU)\b/', implode( '', array_column( $calls, 1 ) ) ), 0 );
check( 'the CDATA wrap is the exporter\'s, as build_item_xml() sends it', TCGiant_Sync_Exporter::description_xml( 'a ]]> b' ), '<Description><![CDATA[a ]]]]><![CDATA[> b]]></Description>' );
check( 'recorded as a push records it: the hash, the template, the listing, the time', array(
	$GLOBALS['W']['meta'][802]['_tcgiant_desc_hash'],
	$GLOBALS['W']['meta'][802]['_tcgiant_tpl_id'],
	$GLOBALS['W']['meta'][802]['_tcgiant_tpl_ver'],
	$GLOBALS['W']['meta'][802]['_tcgiant_tpl_item'],
	$GLOBALS['W']['meta'][802]['_tcgiant_content_hash'],
	abs( (int) $GLOBALS['W']['meta'][802]['_tcgiant_desc_sent_at'] - time() ) < 5,
), array( $render['hash'], (string) $T, '1', '110000000802', $render['content_hash'], true ) );
check( '  not a push: the last-pushed time and version untouched, the product not saved', array( $GLOBALS['W']['meta'][802]['_ebay_export_last_pushed'], $GLOBALS['W']['meta'][802]['_ebay_export_pushed_version'], $b->data['modified'] ), array( $pushed_before, '3.33.0', $modified ) );
$line = said();
check( 'one line each: product, item, template and version, old and new hash, 1 call', 1 === preg_match( '/Re-send: eBay listing 110000000802 of WC #802 was sent template "Clean" version 1, description only \(ReviseFixedPriceItem\): description none recorded -> ' . substr( $render['hash'], 0, 12 ) . ', 1 call\./', $line ), true );
check( 'the next count: unchanged, cost 0', array( count_now()['unchanged'], rs_calls() ), array( 4, array() ) );

// The plain description, for a listing last sent Clean that is now set to None.
fresh();
$n = listing( 811, array( 'description' => 'Holo. A ]]> in the text.' ) );
sent_now( $n );
$GLOBALS['W']['meta'][811]['_tcgiant_tpl_override'] = 'none';
$done = count_now();
check( 'a listing whose template now resolves to None: in "Back to the plain description"', array( array_keys( $done['groups'] ), TCGiant_Sync_Template_Resend::group_name( 'none' ) ), array( array( 'none' ), 'Back to the plain description' ) );
TCGiant_Sync_Template_Resend::test( 'none' );
$calls = rs_calls();
check( '  sent the plain description, exactly as a push builds it', false !== strpos( $calls[0][1], TCGiant_Sync_Exporter::description_xml( private_method( 'TCGiant_Sync_Exporter', 'build_description' )->invoke( exporter(), $n ) ) ), true );
check( '  a "]]>" in it is split, by the exporter\'s own CDATA wrap', false !== strpos( $calls[0][1], 'A ]]]]><![CDATA[> in the text.' ), true );
check( '  and recorded as sent without a template', array( $GLOBALS['W']['meta'][811]['_tcgiant_tpl_id'], $GLOBALS['W']['meta'][811]['_tcgiant_tpl_ver'] ), array( '0', '0' ) );
check( '  the next count: nothing - never sent a template since, and still None', count_now()['looked'], 0 );

// A shop with no template at all gets nothing new.
fresh();
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, TCGiant_Sync_Templates::default_settings() );
foreach ( array( 821, 822, 823 ) as $id ) {
	sent_now( listing( $id ) );
}
listing( 824 );
$done = count_now();
check( 'a shop with no template: nothing counted, nothing to send, no call', array( $done['looked'], $done['groups'], rs_calls() ), array( 0, array(), array() ) );

// eBay's warnings on a description it took: in the Re-send: line of the log,
// and beside Test on 5's OK.
fresh();
listing( 851 );
listing( 852 );
count_now();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Warning', 'Errors' => array(
	array( 'ErrorCode' => '21919028', 'ShortMessage' => 'Removed.', 'LongMessage' => 'Some HTML was removed from the description: data attributes.', 'SeverityCode' => 'Warning' ),
	array( 'ErrorCode' => '21919029', 'ShortMessage' => 'A comment was removed.', 'LongMessage' => '', 'SeverityCode' => 'Warning' ),
) );
said();
$t = TCGiant_Sync_Template_Resend::test( 't' . $T );
check( 'eBay took them with warnings: OK, and eBay\'s words beside each OK', array( $t['ok'], $t['rows'][0]['ok'], $t['rows'][0]['message'] ), array( true, true, 'Some HTML was removed from the description: data attributes. | A comment was removed.' ) );
$lines = array_values( array_filter( $GLOBALS['W']['log'], function ( $l ) { return 0 === strpos( $l[1], 'Re-send: eBay listing 110000000851 ' ); } ) );
check( '  and in its Re-send: line of the log, as a warning', array( count( $lines ), $lines[0][0] ?? '', false !== strpos( $lines[0][1] ?? '', ', 1 call. eBay took it with a warning: Some HTML was removed from the description: data attributes. | A comment was removed.' ) ), array( 1, 'warning', true ) );
check( '  and in the box, beside the OK', false !== strpos( box(), 'OK</span> <span class="tc-warning-text"> eBay took it with a warning: Some HTML was removed from the description: data attributes. | A comment was removed. </span>' ), true );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Success' );
said();
sent_now( wc_get_product( 851 ), 172800 );
$GLOBALS['W']['meta'][851]['_tcgiant_desc_hash'] = 'older';
$t = TCGiant_Sync_Template_Resend::test( 't' . $T );
check( '  one with no warning: OK alone, its line a success', array( $t['rows'][0]['message'], $GLOBALS['W']['log'][ count( $GLOBALS['W']['log'] ) - 2 ][0] ?? '' ), array( '', 'success' ) );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT MAKES A LISTING CHANGED - AND WHAT HOLDS IT FOR A PUSH\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$e = listing( 901 );
sent_now( $e );
check( 'sent Clean v1: unchanged', TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], 'unchanged' );
$e->data['description'] = 'Edited on the website.';
$e->data['modified']    = time();
$v = TCGiant_Sync_Template_Resend::judge( 901 );
check( 'the product\'s description edited, which reaches only the description: its new description goes', array( $v['verdict'], $v['group'] ), array( 'send', 't' . $T ) );
$e->data['description'] = 'Near mint card number 901.';
TCGiant_Sync_Templates::save( $T, array( 'html' => '<h2>{{title}}</h2>{{{description}}}' ) );
check( 'its template saved: changed, in Clean v2', array( TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], TCGiant_Sync_Template_Resend::judge( 901 )['group'], TCGiant_Sync_Template_Resend::judge( 901 )['render']['version'] ), array( 'send', 't' . $T, 2 ) );
check( '  with a 3.34.0 content hash, compared by it, not by the date: the product\'s save does not hold it', TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], 'send' );
$e->data['name'] = 'Card 901, new title';
check( '  its title edited since: held, as the title eBay has is not the one a push would send', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'title_differs' );
TCGiant_Sync_DB::$rows[901]['ebay_title'] = 'Card 901, new title';
check( '    and with eBay\'s title the same, its content hash still holds it: changed since it was sent', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'changed' );
$e->data['name']                          = 'Card 901';
TCGiant_Sync_DB::$rows[901]['ebay_title'] = 'Card 901';
$e->data['regular_price'] = '25.00';
check( '  its price edited since, which eBay is sent: changed', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'changed' );
$e->data['regular_price'] = '10.00';
TCGiant_Sync_DB::$rows[901]['ebay_title'] = 'Card 901 Holo';
check( '  its title edited in Seller Hub, the sync writing eBay\'s: held, the content hash the same', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'title_differs' );
unset( TCGiant_Sync_DB::$rows[901] );
check( '  no row on the Listings table, so no title of eBay\'s to compare: held', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'title_differs' );
TCGiant_Sync_DB::$rows[901] = array( 'product_id' => 901, 'ebay_title' => 'Card 901', 'last_synced' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) );
$e->data['stock'] = 0;
$e->data['name']  = 'Card 901, new title';
check( '  held at quantity 0: said so, as a push needs it restocked first', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'held_empty' );
$e->data['stock'] = 3;
$e->data['name']  = 'Card 901';

// A relist moves the record with the listing (the exporter and the stock
// push follow _tcgiant_tpl_item to the new number): not a reason.
sent_now( $e );
$GLOBALS['W']['meta'][901]['_ebay_item_id']     = '120000000901';
$GLOBALS['W']['meta'][901]['_tcgiant_tpl_item'] = '120000000901';
check( 'relisted, the record following the new listing: unchanged', TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], 'unchanged' );
$GLOBALS['W']['meta'][901]['_tcgiant_tpl_item'] = '110000000901';
check( '  the record of another listing: its content hash not used, the date decides - saved since its push, held', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'held' );
$e->data['modified'] = time() - 3700;
check( '    not saved since: sent', TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], 'send' );
$GLOBALS['W']['meta'][901]['_tcgiant_tpl_item'] = '120000000901';

// Sent before 3.34.0, or by a preview of it: the template-path hash then is
// not used, so the date decides.
$GLOBALS['W']['meta'][901]['_tcgiant_content_hash'] = sha1( 'what 3.33.0 recorded' );
$GLOBALS['W']['meta'][901]['_tcgiant_desc_hash']    = 'older';
check( 'a content hash from before 3.34.0, the product unmodified since its push: sent', TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], 'send' );
$GLOBALS['W']['meta'][901]['_tcgiant_content_hash'] = '2:' . sha1( 'what a preview of 3.34.0 recorded' );
$e->data['modified'] = time() - 3600 + 31;
check( '  one marked "2:", from other facts: not used either - saved since its push, held', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'held' );
// From the push time as recorded, not the clock now: a second passing
// between the two made it 31 seconds, and held (3.38.0).
$e->data['modified'] = strtotime( $GLOBALS['W']['meta'][901]['_ebay_export_last_pushed'] . ' UTC' ) + 30;
check( '  saved within the push\'s own 30 seconds: sent', TCGiant_Sync_Template_Resend::judge( 901 )['verdict'], 'send' );
$e->data['modified'] = time() - 3600 + 31;
check( '  saved a second later (a sale, an edit): held', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'held' );
unset( $e->data['modified'] );
check( '  no date at all: held, when in doubt', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'held' );
$e->data['modified'] = time() - 7200;
unset( $GLOBALS['W']['meta'][901]['_ebay_export_last_pushed'] );
check( '  never pushed from this site: held, under its own reason', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'never_pushed' );
TCGiant_Sync_DB::$rows[901]['last_synced'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
check( '  only ever imported, untouched since its last sync: still held - a sync dates last_synced whatever the website\'s product says', TCGiant_Sync_Template_Resend::judge( 901 )['reason'], 'never_pushed' );

// The review's case: a listing imported as "Charizard 4/102", renamed on the
// website and never pushed. A GTC renewal, or the weekly sync, then dates
// last_synced after the rename: it must still be held, never sent a
// description naming the new title beside eBay's old one.
$c = listing( 902, array( 'name' => 'Charizard 4/102', 'modified' => time() - 7200 ), array( '_ebay_export_last_pushed' => null, '_ebay_origin' => 'import' ) );
TCGiant_Sync_DB::$rows[902]['last_synced'] = gmdate( 'Y-m-d H:i:s', time() - 7000 );
$c->data['name']     = 'Charizard 4/102 Holo PSA 9';
$c->data['modified'] = time() - 3600;
check( 'imported, renamed on the website, not pushed: held', TCGiant_Sync_Template_Resend::judge( 902 )['verdict'], 'left' );
TCGiant_Sync_DB::$rows[902]['last_synced'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
$v = TCGiant_Sync_Template_Resend::judge( 902 );
check( '  a renewal or a sync then dates last_synced: still held, for its title', array( $v['verdict'], $v['reason'] ), array( 'left', 'title_differs' ) );
TCGiant_Sync_DB::$rows[902]['ebay_title'] = 'Charizard 4/102 Holo PSA 9';
check( '  and with the titles alike (an attribute edited instead): still held, never pushed from here', TCGiant_Sync_Template_Resend::judge( 902 )['reason'], 'never_pushed' );
$GLOBALS['W']['meta'][902]['_ebay_export_last_pushed'] = gmdate( 'Y-m-d H:i:s', time() - 30 );
check( '  pushed once since: it can be re-sent', TCGiant_Sync_Template_Resend::judge( 902 )['verdict'], 'send' );

// A push that did not live to save its listing, whose listing the sync then
// links: the date the link writes is the link's, so it counts as none.
$u = listing( 903, array( 'modified' => time() - 7200 ), array( '_ebay_export_last_pushed' => null, '_ebay_export_status' => 'failed' ) );
check( 'the sync completes an unfinished push, dating it now', TCGiant_Sync_Listing_Link::complete_unfinished_push( $u ), true );
check( '  the date marked as the link\'s, and nothing else written beside it', array( $GLOBALS['W']['meta'][903]['_ebay_export_last_pushed'] === $GLOBALS['W']['meta'][903][ TCGiant_Sync_Listing_Link::LINK_DATED_META ], (string) $GLOBALS['W']['meta'][903]['_ebay_export_status'] ), array( true, 'pushed' ) );
check( '  so held, as never pushed', TCGiant_Sync_Template_Resend::judge( 903 )['reason'], 'never_pushed' );
$GLOBALS['W']['meta'][903]['_ebay_export_last_pushed'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
check( '  a push of it since, with a date of its own: the date rule again, and sent', TCGiant_Sync_Template_Resend::judge( 903 )['verdict'], 'send' );
listing( 904, array(), array( '_ebay_export_last_pushed' => null, '_ebay_export_status' => 'pushing' ) );
TCGiant_Sync_Listing_Link::complete_unfinished_push_now( 904 );
check( 'the same, straight to the table: marked, and held', array( $GLOBALS['W']['meta'][904]['_ebay_export_last_pushed'] === $GLOBALS['W']['meta'][904][ TCGiant_Sync_Listing_Link::LINK_DATED_META ], TCGiant_Sync_Template_Resend::judge( 904 )['reason'] ), array( true, 'never_pushed' ) );
listing( 906, array(), array( '_ebay_export_last_pushed' => '2026-01-02 03:04:05', '_ebay_export_status' => 'failed' ) );
TCGiant_Sync_Listing_Link::complete_unfinished_push_now( 906 );
check( '  one with a push of its own on record keeps that date, unmarked', array( $GLOBALS['W']['meta'][906]['_ebay_export_last_pushed'], isset( $GLOBALS['W']['meta'][906][ TCGiant_Sync_Listing_Link::LINK_DATED_META ] ) ), array( '2026-01-02 03:04:05', false ) );

// The import's own link of a listing such a push made (3.28.7) dates it
// through record_listing_made(): marked the same. A push that finishes takes
// the mark off, even in the same second.
$m = listing( 907, array( 'modified' => time() - 7200 ), array( '_ebay_export_last_pushed' => null ) );
TCGiant_Sync_Exporter::record_listing_made( $m, array( 'item_id' => '110000000907', 'action' => 'updated', 'link_dated' => true ) );
check( 'the import link of a listing an unfinished push made: dated now, marked, held', array( $GLOBALS['W']['meta'][907]['_ebay_export_last_pushed'] === ( $GLOBALS['W']['meta'][907][ TCGiant_Sync_Listing_Link::LINK_DATED_META ] ?? '' ), TCGiant_Sync_Template_Resend::judge( 907 )['reason'] ), array( true, 'never_pushed' ) );
TCGiant_Sync_Exporter::record_listing_made( $m, array( 'item_id' => '110000000907', 'action' => 'updated' ) );
check( '  a push that finishes, in the same second: the mark gone, and the date rule again', array( isset( $GLOBALS['W']['meta'][907][ TCGiant_Sync_Listing_Link::LINK_DATED_META ] ), TCGiant_Sync_Template_Resend::judge( 907 )['verdict'] ), array( false, 'send' ) );

// An imported listing that keeps eBay's values (3.28.8): its Update sends the
// Description too, so a template counts as a changed Description for it -
// once it has been pushed from here.
fresh();
$imported = listing( 911, array(), array( '_ebay_export_last_pushed' => null, '_ebay_origin' => 'import' ) );
TCGiant_Sync_DB::$rows[911]['last_synced'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
$v = TCGiant_Sync_Template_Resend::judge( 911 );
check( 'an imported listing, never pushed from here: held until it is pushed once', array( $v['verdict'], $v['reason'] ), array( 'left', 'never_pushed' ) );
$GLOBALS['W']['meta'][911]['_ebay_export_last_pushed'] = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$v = TCGiant_Sync_Template_Resend::judge( 911 );
check( '  pushed once, not saved since: changed, sent', array( $v['verdict'], $v['group'] ), array( 'send', 't' . $T ) );
$xml = item_xml( $imported, push_settings( $imported ) );
check( '  its Update sends the Description too: the template does reach it', false !== strpos( $xml, '<Description><![CDATA[<h2>' ), true );
check( 'Verify never writes the description\'s record', isset( $GLOBALS['W']['meta'][911]['_tcgiant_desc_hash'] ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTEST ON 5 LOCKS AND UNLOCKS RE-SEND ALL\n" . str_repeat( '=', 124 ) . "\n";

fresh();
for ( $i = 1; $i <= 40; $i++ ) {
	listing( 4000 + $i );
}
count_now();
$g = 't' . $T;
check( 'before a test: Re-send all refused, "Test on 5 first"', TCGiant_Sync_Template_Resend::start( $g )['message'], 'Test on 5 first: Re-send all unlocks once a test of this template, as it is now, comes back clean.' );
check( '  and nothing queued or sent', array( run_state(), as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ), rs_calls() ), array( array(), array(), array() ) );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = ebay_refused( 'The description contains disallowed content.' );
$failed = TCGiant_Sync_Template_Resend::test( $g );
check( 'a test eBay refused: not clean, eBay\'s own words beside each', array( $failed['ok'], $failed['rows'][0]['message'], count( $failed['rows'] ) ), array( false, 'The description contains disallowed content.', 5 ) );
check( '  still locked', TCGiant_Sync_Template_Resend::unlocked( $g ), false );
$recount = count_now();
check( '  the hashes stay, so they still count as changed; the five eBay refused today wait for tomorrow', array( $recount['groups'][ $g ]['count'], $recount['reasons'] ), array( 35, array( 'refused_today' => 5 ) ) );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );
check( '  a test\'s refusals are not put on Re-send all\'s list for a later day', rs_option( TCGiant_Sync_Template_Resend::RETRY_OPTION ), array() );
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 1997 ) );
rs_calls();
$t = TCGiant_Sync_Template_Resend::test( $g );
check( 'Test on 5 with room for three of the day\'s bulk pushes: refused, nothing sent, nothing taken', array( $t['message'], rs_calls(), TCGiant_Sync_Exporter::bulk_pushed_today() ), array( 'Today\'s bulk-push limit has no room for 5 more. Bulk pushes today: 1,997 of 2,000. It resets at midnight UTC.', array(), 1997 ) );
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 0 ) );
$GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'send' ] = array( array( 1, ( time() + 300 ) . '|another-request' ) );
check( 'Test on 5 while another request sends: refused, nothing sent', array( TCGiant_Sync_Template_Resend::test( $g )['message'], rs_calls() ), array( 'A re-send is going to eBay right now. Try again in a minute.', array() ) );
unset( $GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'send' ] );
$clean = TCGiant_Sync_Template_Resend::test( $g );
check( 'a clean test: five sent, five OK, unlocked', array( $clean['ok'], count( $clean['rows'] ), count( rs_calls() ), TCGiant_Sync_Template_Resend::unlocked( $g ) ), array( true, 5, 5, true ) );
check( '  each with its listing, for the link; none of the five eBay refused today sent again', array( $clean['rows'][0]['item_id'], $clean['rows'][0]['name'] ), array( '110000004006', 'Card 4006' ) );
TCGiant_Sync_Templates::save( $T, array( 'html' => '<h3>{{title}}</h3>{{{description}}}' ) );
check( 'the template saved again: locked again', TCGiant_Sync_Template_Resend::unlocked( $g ), false );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
check( '  tested again: unlocked', TCGiant_Sync_Template_Resend::unlocked( $g ), true );
TCGiant_Sync_Templates::save( $T, array( 'html' => '<h3>{{title}}</h3>{{{description}}}{{{block.footer}}}' ) );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Templates::save_block( 'footer', 'Footer', array( 'en' => 'Thanks for looking.' ) );
check( 'a block it uses saved: locked again too (the template\'s hash moved)', TCGiant_Sync_Template_Resend::unlocked( $g ), false );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
$was = TCGiant_Sync_Templates::get( $T );
TCGiant_Sync_Templates::save( $T, array() );
check( 'the template saved again with nothing changed: a new version, the same hash, and locked again', array( TCGiant_Sync_Template_Resend::unlocked( $g ), TCGiant_Sync_Templates::get( $T )['version'] - $was['version'], TCGiant_Sync_Templates::get( $T )['hash'] === $was['hash'] ), array( false, 1, true ) );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
check( 'Re-send all, once unlocked: started', TCGiant_Sync_Template_Resend::start( $g )['ok'], true );
check( '  a second while it goes: refused', TCGiant_Sync_Template_Resend::start( $g )['message'], 'A Re-send all is already going. Stop it first, or wait for it to finish.' );
check( '  Test on 5 while it goes: refused', TCGiant_Sync_Template_Resend::test( $g )['message'], 'A Re-send all is already going. Stop it first, or wait for it to finish.' );
TCGiant_Sync_Template_Resend::stop();
TCGiant_Sync_Template_Resend::queue_scan( 'harness' );
check( 'with a count waiting after a change: Test on 5 refused until it is done', TCGiant_Sync_Template_Resend::test( $g )['message'], 'The count is being made again after your last change. The buttons work again once it is done; reload this page in a minute or two.' );
check( '  and Re-send all', TCGiant_Sync_Template_Resend::start( $g )['ok'], false );

// The group going back to the plain description has a test of its own.
fresh();
$back = listing( 4101 );
sent_now( $back );
$GLOBALS['W']['meta'][4101]['_tcgiant_tpl_override'] = 'none';
count_now();
check( 'the plain description\'s group: locked until its own test', array( TCGiant_Sync_Template_Resend::unlocked( 'none' ), TCGiant_Sync_Template_Resend::start( 'none' )['ok'] ), array( false, false ) );
TCGiant_Sync_Template_Resend::test( 'none' );
check( '  tested clean: unlocked', TCGiant_Sync_Template_Resend::unlocked( 'none' ), true );

// Test on 5 sends only listings still in the group pressed: one given
// another template since the count is judged into that one's, and left.
fresh();
$other_tpl = make_template( array( 'name' => 'Other', 'html' => '<div>{{{description}}}</div>' ) );
for ( $i = 1; $i <= 6; $i++ ) {
	listing( 4200 + $i );
}
count_now();
$GLOBALS['W']['meta'][4201]['_tcgiant_tpl_override'] = (string) $other_tpl;
rs_calls();
$t = TCGiant_Sync_Template_Resend::test( $g );
check( 'Test on 5: a listing given another template since the count is not sent with this group', array( array_map( function ( $r ) { return $r['product_id']; }, $t['rows'] ), count( rs_calls() ) ), array( array( 4202, 4203, 4204, 4205, 4206 ), 5 ) );

// The budget asked before each of the five: this site at half its own
// allowance after the first call, the other four are not sent.
fresh();
for ( $i = 1; $i <= 5; $i++ ) {
	listing( 4300 + $i );
}
count_now();
/** eBay played, each call counted as the API's own counter counts it. */
class Counting_World_API extends Template_World_API {
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$answer = parent::trading_api_request( $call_name, $xml_body, $allow_retry, $max_body_bytes );
		own_calls( (int) ( $GLOBALS['W']['options'][ TCGiant_Sync_API::CALL_COUNT_PREFIX . gmdate( 'Y-m-d' ) ] ?? 0 ) + 1 );
		return $answer;
	}
}
$world_api = $GLOBALS['API'];
$counting  = new Counting_World_API();
$GLOBALS['API'] = $counting;
$api_slot->setValue( null, $counting );
relay_said( 1000, 2 );
own_calls( 0 );
$bulk = TCGiant_Sync_Exporter::bulk_pushed_today();
$t    = TCGiant_Sync_Template_Resend::test( $g );
check( 'Test on 5, this site at half its own allowance after the first call: one sent, four not', array( count( $counting->calls ), $t['rows'][0]['ok'], $t['rows'][1]['message'], $t['rows'][4]['ok'], TCGiant_Sync_Exporter::bulk_pushed_today() - $bulk ), array( 1, true, 'Not sent. This site has used half of its own eBay calls for today. The rest are kept for stock and orders.', false, 1 ) );
$GLOBALS['API'] = $world_api;
$api_slot->setValue( null, $world_api );
roomy();

// A Re-send all starts with no list of another run's refusals: they were
// that run's, to be tried on a later day with it.
fresh();
for ( $i = 1; $i <= 6; $i++ ) {
	listing( 4400 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
update_option( TCGiant_Sync_Template_Resend::RETRY_OPTION, array( 4999 => gmdate( 'Y-m-d', time() - 86400 ) ) );
TCGiant_Sync_Template_Resend::start( $g );
check( 'Re-send all started: the list of an earlier run\'s refusals cleared', rs_option( TCGiant_Sync_Template_Resend::RETRY_OPTION ), array() );
run_next();
check( '  so it ends when its own listings are sent, waiting for no later day', array( run_state()['status'], run_state()['sent'] ), array( 'done', 1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nRE-SEND ALL: BATCHES OF 50, THE DAY'S BULK-PUSH LIMIT, A TWELFTH OF IT IN TWO HOURS\n" . str_repeat( '=', 124 ) . "\n";

fresh();
for ( $i = 1; $i <= 75; $i++ ) {
	listing( 2000 + $i, $i <= 2 ? array( 'stock' => 0 ) : array() );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
$sent_by_test = count( rs_calls() );
$bulk_before  = TCGiant_Sync_Exporter::bulk_pushed_today();
check( 'the test took five of the day\'s bulk pushes', array( $sent_by_test, $bulk_before ), array( 5, 5 ) );
TCGiant_Sync_Template_Resend::start( $g );
check( 'queued: the 75 the count found, those at quantity 0 last though their IDs come first', array( run_state()['total'], get_option( TCGiant_Sync_Template_Resend::QUEUE_PREFIX . '0' )[0], get_option( TCGiant_Sync_Template_Resend::QUEUE_PREFIX . '0' )[73], get_option( TCGiant_Sync_Template_Resend::QUEUE_PREFIX . '0' )[74] ), array( 75, 2003, 2001, 2002 ) );
run_next();
$first = rs_calls();
check( 'the first batch: 50 calls, none of them a listing the test already sent', array( count( $first ), run_state()['sent'], run_state()['dropped'], run_state()['dropped_why'] ), array( 50, 50, 5, array( 'unchanged' => 5 ) ) );
check( '  50 more of the day\'s bulk pushes: the dropped five given back', TCGiant_Sync_Exporter::bulk_pushed_today(), 55 );
check( '  the next batch waits a moment', count( as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ) ), 1 );
run_next();
check( 'the second: the last 20, quantity 0 last', array( count( $calls = rs_calls() ), substr_count( $calls[18][1], '110000002001' ), substr_count( $calls[19][1], '110000002002' ), run_state()['status'], run_state()['end_why'] ), array( 20, 1, 1, 'done', 'done' ) );
check( '  75 of the day\'s bulk pushes in all, the test\'s five with them', TCGiant_Sync_Exporter::bulk_pushed_today(), 75 );
check( '  its queue deleted', get_option( TCGiant_Sync_Template_Resend::QUEUE_PREFIX . '0', 'gone' ), 'gone' );
check( '  and the next count: all unchanged', array( count_now()['unchanged'], rs_calls() ), array( 75, array() ) );
check( 'the window counted every re-send, the test\'s too', TCGiant_Sync_Template_Resend::window_used( time() ), 75 );

update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 10 ) );
TCGiant_Sync_Exporter::give_back_bulk_push_allowance( 5, gmdate( 'Y-m-d', time() - 86400 ) );
check( 'what yesterday\'s batch did not use is not given back from today\'s count', TCGiant_Sync_Exporter::bulk_pushed_today(), 10 );
TCGiant_Sync_Exporter::give_back_bulk_push_allowance( 5, gmdate( 'Y-m-d' ) );
TCGiant_Sync_Exporter::give_back_bulk_push_allowance( 50, gmdate( 'Y-m-d' ) );
check( '  today\'s is, and never below 0', array( TCGiant_Sync_Exporter::bulk_pushed_today(), get_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION )['count'] ), array( 0, 0 ) );

// The bulk-push limit is shared: what bulk pushes took is not there for re-sends, and the other way round.
fresh();
for ( $i = 1; $i <= 30; $i++ ) {
	listing( 2100 + $i );
}
TCGiant_Sync_Exporter::set_bulk_push_cap( 50 );
check( 'a cap of 50: four re-sends in any two hours (a twelfth)', TCGiant_Sync_Template_Resend::window_cap(), 4 );
TCGiant_Sync_Exporter::set_bulk_push_cap( 2000 );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Exporter::take_bulk_push_allowance( 1995 );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
run_next();
check( 'bulk pushes have taken the rest of the day: no re-send, paused until tomorrow', array( rs_calls(), run_state()['status'], run_state()['pause']['why'] ), array( array(), 'paused', 'limit' ) );
$tomorrow = strtotime( gmdate( 'Y-m-d', time() + 86400 ) . ' 00:05:00 UTC' );
check( '  it tries again at 00:05 UTC tomorrow', array( run_state()['pause']['until'], as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK )[0]['time'] ), array( $tomorrow, $tomorrow ) );
update_option( TCGiant_Sync_Exporter::BULK_PUSHED_OPTION, array( 'day' => gmdate( 'Y-m-d' ), 'count' => 1990 ) );
run_next();
check( 'room for ten: ten sent', count( rs_calls() ), 10 );
check( '  and bulk pushes then find the day used up', TCGiant_Sync_Exporter::take_bulk_push_allowance( 5 ), 0 );
TCGiant_Sync_Template_Resend::stop();

// A twelfth of the day's limit in any two hours.
fresh();
for ( $i = 1; $i <= 30; $i++ ) {
	listing( 2200 + $i );
}
TCGiant_Sync_Exporter::set_bulk_push_cap( 120 );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
run_next();
check( 'a limit of 120: ten in two hours, so five more after the test\'s five', count( rs_calls() ), 5 );
run_next();
check( '  then the window is full: nothing sent, paused', array( rs_calls(), run_state()['pause']['why'] ), array( array(), 'window' ) );
$minute = time() - ( time() % 60 );
check( '  it tries again when the first of them leaves the window, two hours on', run_state()['pause']['until'], $minute + 60 + 7200 );
run_next();
check( '  run early: still nothing sent', rs_calls(), array() );
update_option( TCGiant_Sync_Template_Resend::WINDOW_OPTION, array( $minute - 7200 => 10 ) );
check( 'a minute two hours back still counts, until two hours after its end', TCGiant_Sync_Template_Resend::window_used( time() ), 10 );
update_option( TCGiant_Sync_Template_Resend::WINDOW_OPTION, array( $minute - 7260 => 10 ) );
check( '  a minute before that does not', TCGiant_Sync_Template_Resend::window_used( time() ), 0 );
run_next();
check( 'two hours on: ten more', count( rs_calls() ), 10 );
check( '  and it says it is running again, with no pause', array( run_state()['status'], run_state()['pause'] ), array( 'running', null ) );
TCGiant_Sync_Template_Resend::stop();
TCGiant_Sync_Exporter::set_bulk_push_cap( 2000 );

// A listing that now gets another template is dropped; the template saved
// again, or taken off, ends the run.
fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	listing( 2250 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$GLOBALS['W']['meta'][2257]['_tcgiant_tpl_override'] = (string) $other;
rs_calls();
run_next();
check( 'a listing set to another template since the count: dropped, not sent with the wrong one', array( count( rs_calls() ), run_state()['dropped_why'] ), array( 6, array( 'unchanged' => 5, 'moved' => 1 ) ) );
fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	listing( 2270 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
TCGiant_Sync_Templates::save( $T, array( 'html' => '<h1>{{title}}</h1>{{{description}}}<p>New.</p>' ) );
rs_calls();
run_next();
check( 'the template saved during a Re-send all: it ends, nothing sent, the queue gone', array( rs_calls(), run_state()['status'], run_state()['end_why'], get_option( TCGiant_Sync_Template_Resend::QUEUE_PREFIX . '0', 'gone' ) ), array( array(), 'stopped', 'changed', 'gone' ) );
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
get_post( $T )->post_status = 'draft';
run_next();
get_post( $T )->post_status = 'publish';
check( '  set to Draft: it ends too', array( rs_calls(), run_state()['end_why'] ), array( array(), 'template_gone' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPAUSES FOR THE BUDGET: STALE FIGURE, SPENT DAY, A QUARTER LEFT, HALF THIS SITE'S OWN\n" . str_repeat( '=', 124 ) . "\n";

check( 'a roomy day: no pause', TCGiant_Sync_Template_Resend::pause(), null );
delete_transient( 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ) );
check( 'no figure for every shop: paused, looks again in 30 minutes', TCGiant_Sync_Template_Resend::pause(), array( 'why' => 'fleet_unknown', 'until' => time() + 1800 ) );
relay_said( 1000, 1000, 7201 );
check( '  a figure over two hours old: the same', TCGiant_Sync_Template_Resend::pause()['why'] ?? '', 'fleet_unknown' );
relay_said( 1000, 1000, 7000 );
check( '  one just under two hours: goes', TCGiant_Sync_Template_Resend::pause(), null );
relay_said( 40000, 1000 );
check( 'the day spent (80% of 50,000): paused until tomorrow', TCGiant_Sync_Template_Resend::pause(), array( 'why' => 'budget', 'until' => $tomorrow ) );
relay_said( 30001, 1000 );
check( 'every shop with under a quarter of the ceiling left (9,999 of 40,000): paused until tomorrow', TCGiant_Sync_Template_Resend::pause(), array( 'why' => 'fleet_low', 'until' => $tomorrow ) );
relay_said( 30000, 1000 );
check( '  exactly a quarter left: goes', TCGiant_Sync_Template_Resend::pause(), null );
relay_said( 29000, 0, 60, 100 );
own_calls( 1101 );
check( 'this site\'s own calls since the figure count too: 29,000 then, and 1,001 since', TCGiant_Sync_Template_Resend::pause()['why'] ?? '', 'fleet_low' );
own_calls( 1099 );
check( '  999 since: goes', TCGiant_Sync_Template_Resend::pause(), null );
relay_said( 1000, 1000 );
own_calls( 500 );
check( 'this site at half its own 1,000 for the day: paused until tomorrow, the rest kept for stock and orders', TCGiant_Sync_Template_Resend::pause(), array( 'why' => 'own_half', 'until' => $tomorrow ) );
own_calls( 499 );
check( '  at 499: goes', TCGiant_Sync_Template_Resend::pause(), null );
relay_said( 1000, 10000 );
own_calls( 5000 );
check( '  a paid site, 5,000 of 10,000: paused', TCGiant_Sync_Template_Resend::pause()['why'] ?? '', 'own_half' );
roomy();
TCGiant_Sync_OAuth::$connected = false;
check( 'not connected: paused', TCGiant_Sync_Template_Resend::pause()['why'] ?? '', 'not_connected' );
TCGiant_Sync_OAuth::$connected = true;

fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	listing( 2300 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
own_calls( 500 );
run_next();
check( 'Re-send all at half the site\'s own allowance: nothing sent, paused, said once', array( rs_calls(), run_state()['status'], run_state()['pause']['why'], substr_count( said(), 'Re-send: paused. This site has used half of its own eBay calls for today' ) ), array( array(), 'paused', 'own_half', 1 ) );
run_next();
check( '  run again, still paused: not said again', substr_count( said(), 'Re-send: paused.' ), 0 );
$bulk = TCGiant_Sync_Exporter::bulk_pushed_today();
own_calls( 0 );
run_next();
check( 'the next day, room again: the rest sent', array( count( rs_calls() ), run_state()['status'] ), array( 7, 'done' ) );

// The budget again before each call: a figure gone part way through a batch.
fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	listing( 2400 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
add_filter( 'tcgiant_sync_ebay_description', $spend = function ( $html, $product ) {
	if ( 2409 === $product->get_id() ) {
		own_calls( 500 );
	}
	return $html;
}, 10, 2 );
$bulk = TCGiant_Sync_Exporter::bulk_pushed_today();
run_next();
remove_filter( 'tcgiant_sync_ebay_description', $spend, 10 );
check( 'the budget asked before each call: three sent, then paused before the fourth', array( count( rs_calls() ), run_state()['pause']['why'], run_state()['cursor'] ), array( 3, 'own_half', 8 ) );
check( '  what it did not send given back to the day\'s bulk pushes', TCGiant_Sync_Exporter::bulk_pushed_today() - $bulk, 3 );
own_calls( 0 );
run_next();
check( '  then it carries on from the listing it stopped at', array( count( $calls = rs_calls() ), false !== strpos( $calls[0][1], '110000002409' ) ), array( 4, true ) );

// eBay's own answer that the day is spent: not a refusal of the listing.
fresh();
for ( $i = 1; $i <= 8; $i++ ) {
	listing( 2500 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted. Will resume tomorrow.', array( 'daily_budget' => true ) );
rs_calls();
run_next();
check( 'refused for the day\'s budget: paused until tomorrow, the listing goes again, nothing failed', array( count( rs_calls() ), run_state()['pause']['why'], run_state()['cursor'], run_state()['failed'], get_post_meta( 2506, TCGiant_Sync_Exporter::RESEND_FAILED_META, true ) ), array( 1, 'budget', 5, 0, '' ) );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
run_next();
check( 'eBay not reached: paused 30 minutes, the listing goes again', array( run_state()['pause']['why'], run_state()['pause']['until'] - time() <= 1800, run_state()['failed'] ), array( 'unreachable', true, 0 ) );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'no_token', 'No valid eBay token.' );
run_next();
check( 'no token: paused, nothing failed', array( run_state()['pause']['why'], run_state()['failed'] ), array( 'not_connected', 0 ) );
check( '  neither the day\'s budget nor a missing token counts as eBay not answering about the listing: once, for the timeout', get_post_meta( 2506, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['no_answer'] ?? 0, 1 );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );
TCGiant_Sync_Template_Resend::stop();

// Test on 5 when eBay is not reached: the rest of the five are not sent.
fresh();
for ( $i = 1; $i <= 8; $i++ ) {
	listing( 2550 + $i );
}
count_now();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
$bulk = TCGiant_Sync_Exporter::bulk_pushed_today();
rs_calls();
$t = TCGiant_Sync_Template_Resend::test( $g );
check( 'Test on 5, eBay not reached at the first: one call, the other four not sent, not clean', array( count( rs_calls() ), $t['ok'], $t['rows'][4]['message'], TCGiant_Sync_Exporter::bulk_pushed_today() - $bulk ), array( 1, false, 'Not sent. eBay could not be reached, or was busy.', 1 ) );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );

// The relay's figure with no allowance of this site's own: the half
// pause cannot hold re-sends back, so the log says so, once a UTC day.
fresh();
relay_said( 1000, 0 );
said();
check( 'no allowance of this site\'s own in the relay\'s figure: not paused for it', TCGiant_Sync_Template_Resend::pause(), null );
TCGiant_Sync_Template_Resend::pause();
$lines = array_values( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'says nothing of this site\'s own eBay allowance' ); } ) );
check( '  said once that day, as a warning', array( count( $lines ), $lines[0][0] ?? '' ), array( 1, 'warning' ) );
said();
set_transient( TCGiant_Sync_Template_Resend::NO_CAP_TRANSIENT, gmdate( 'Y-m-d', time() - 86400 ) );
TCGiant_Sync_Template_Resend::pause();
check( '  and again the next day', substr_count( said(), 'says nothing of this site\'s own eBay allowance' ), 1 );
roomy();
TCGiant_Sync_Template_Resend::pause();
check( '  with the allowance there: nothing said', said(), '' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREFUSED BY EBAY: THE LISTING STAYS CHANGED, TRIED ON 3 LATER DAYS, THEN NEEDS ATTENTION\n" . str_repeat( '=', 124 ) . "\n";

fresh();
for ( $i = 1; $i <= 7; $i++ ) {
	listing( 2600 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = ebay_refused( 'Item description contains an invalid character.' );
rs_calls();
run_next();
$record = get_post_meta( 2606, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
check( 'two refused: counted, kept with eBay\'s words, the hashes left alone', array( run_state()['failed'], $record['tries'], $record['message'], $record['attention'], isset( $GLOBALS['W']['meta'][2606]['_tcgiant_desc_hash'] ) ), array( 2, 1, 'Item description contains an invalid character.', false, false ) );
private_method( 'TCGiant_Sync_Template_Resend', 'failed' )->invoke( null, 2606, '110000002606', 'Item description contains an invalid character.', false, true );
check( '  refused again the same day: still one day of four', get_post_meta( 2606, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['tries'], 1 );
check( '  eBay\'s message in the log', false !== strpos( said(), 'Re-send: eBay refused the description of listing 110000002606 of WC #2606: Item description contains an invalid character. Its description on eBay is as it was. That was day 1 of 4' ), true );
check( '  waiting for a later day', array( run_state()['status'], array_keys( rs_option( TCGiant_Sync_Template_Resend::RETRY_OPTION ) ), as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK )[0]['time'] ), array( 'waiting_retry', array( 2606, 2607 ), $tomorrow ) );
rs_calls();
run_next();
check( '  run again the same day: nothing tried', rs_calls(), array() );
for ( $day = 2; $day <= 4; $day++ ) {
	// The next day, as far as the run and the record can tell.
	$state              = run_state();
	$state['retry_day'] = gmdate( 'Y-m-d', time() - 86400 );
	update_option( TCGiant_Sync_Template_Resend::STATE_OPTION, $state );
	foreach ( array( 2606, 2607 ) as $id ) {
		$r        = get_post_meta( $id, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
		$r['day'] = gmdate( 'Y-m-d', time() - 86400 );
		update_post_meta( $id, TCGiant_Sync_Exporter::RESEND_FAILED_META, $r );
	}
	if ( 4 === $day ) {
		$GLOBALS['API']->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Warning' );
		add_filter( 'tcgiant_sync_ebay_description', $refuse_one = function ( $html, $product ) {
			if ( 2606 === $product->get_id() ) {
				$GLOBALS['API']->answers['ReviseFixedPriceItem'] = ebay_refused( 'Still no.' );
			} else {
				$GLOBALS['API']->answers['ReviseFixedPriceItem'] = array( 'Ack' => 'Warning' );
			}
			return $html;
		}, 10, 2 );
	}
	run_next();
	run_next();
	check( "  day $day: tried again", count( rs_calls() ), 2 );
}
remove_filter( 'tcgiant_sync_ebay_description', $refuse_one, 10 );
$record = get_post_meta( 2606, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
check( 'refused on the first day and 3 later ones: needs attention, not tried again', array( $record['tries'], $record['attention'], run_state()['status'], run_state()['attention'] ), array( 4, true, 'done', 1 ) );
check( '  eBay took the other on its fourth day, a Warning: its record gone, its hash recorded', array( get_post_meta( 2607, TCGiant_Sync_Exporter::RESEND_FAILED_META, true ), '' !== get_post_meta( 2607, '_tcgiant_desc_hash', true ) ), array( '', true ) );
check( 'the next count leaves it out: needs attention', count_now()['reasons'], array( 'attention' => 1 ) );
// A push of it succeeds: attention clears.
$p2606 = wc_get_product( 2606 );
TCGiant_Sync_Exporter::record_listing_made( $p2606, array( 'item_id' => '110000002606', 'action' => 'updated', 'desc' => exporter()->resend_description( $p2606, exporter()->resend_settings( $p2606 ) ) ) );
check( 'a push of it that eBay took clears it', get_post_meta( 2606, TCGiant_Sync_Exporter::RESEND_FAILED_META, true ), '' );
$GLOBALS['W']['meta'][2606][ TCGiant_Sync_Exporter::RESEND_FAILED_META ] = array( 'item' => '110000002606', 'tries' => 2, 'day' => '2026-01-01', 'message' => 'x', 'attention' => false );
TCGiant_Sync_Exporter::record_resend( 2606, exporter()->resend_description( $p2606, exporter()->resend_settings( $p2606 ) ), '110000002606' );
check( '  and so does a re-send', get_post_meta( 2606, TCGiant_Sync_Exporter::RESEND_FAILED_META, true ), '' );

// eBay says the listing has ended: no later day helps.
fresh();
listing( 2701 );
count_now();
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = ebay_refused( 'You are not allowed to revise ended listings.', '291' );
TCGiant_Sync_Template_Resend::test( $g );
check( 'eBay says it has ended: needs attention at once', get_post_meta( 2701, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['attention'] ?? null, true );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );

// Stopped, and Re-send all started again the same day: the listings eBay
// refused today are not sent again until tomorrow.
fresh();
for ( $i = 1; $i <= 8; $i++ ) {
	listing( 2750 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = ebay_refused( 'Not today.' );
rs_calls();
run_next();
check( 'three refused in a Re-send all', array( count( rs_calls() ), run_state()['failed'] ), array( 3, 3 ) );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );
TCGiant_Sync_Template_Resend::stop();
TCGiant_Sync_Template_Resend::start( $g );
run_next();
check( '  stopped and started again the same day: none of the three sent again, each dropped as refused today', array( rs_calls(), run_state()['dropped_why'], run_state()['status'] ), array( array(), array( 'unchanged' => 5, 'refused_today' => 3 ), 'done' ) );
check( '  nor by Test on 5', TCGiant_Sync_Template_Resend::judge( 2756, true )['reason'], 'refused_today' );

// eBay never answering about one listing: the third time in a row is taken
// as a refusal, so the run moves on rather than waiting on it for good.
fresh();
for ( $i = 1; $i <= 8; $i++ ) {
	listing( 2770 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
add_filter( 'tcgiant_sync_ebay_description', $no_answer = function ( $html, $product ) {
	$GLOBALS['API']->answers['ReviseFixedPriceItem'] = 2776 === $product->get_id() ? new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) : array( 'Ack' => 'Success' );
	return $html;
}, 10, 2 );
rs_calls();
said();
run_next();
check( 'no answer about a listing: paused, the time counted, nothing refused', array( count( rs_calls() ), run_state()['pause']['why'], run_state()['failed'], get_post_meta( 2776, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['no_answer'] ?? 0 ), array( 1, 'unreachable', 0, 1 ) );
run_next();
check( '  a second time in a row: paused again', array( count( rs_calls() ), run_state()['failed'], get_post_meta( 2776, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['no_answer'] ?? 0 ), array( 1, 0, 2 ) );
run_next();
$record = get_post_meta( 2776, TCGiant_Sync_Exporter::RESEND_FAILED_META, true );
check( '  the third: taken as refused, kept for a later day, and the run goes on to the rest', array( count( rs_calls() ), run_state()['failed'], run_state()['sent'], $record['tries'], $record['no_answer'], array_keys( rs_option( TCGiant_Sync_Template_Resend::RETRY_OPTION ) ) ), array( 3, 1, 2, 1, 0, array( 2776 ) ) );
check( '  said, with what came back', false !== strpos( said(), 'Re-send: eBay refused the description of listing 110000002776 of WC #2776: eBay gave no answer about it 3 times in a row: cURL error 28: timed out' ), true );
remove_filter( 'tcgiant_sync_ebay_description', $no_answer, 10 );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );
TCGiant_Sync_Template_Resend::stop();

// eBay's own limit on calls is about every call, not the listing: an HTTP
// 429, or its 518 or 10007 without the plugin's daily-budget mark, pauses
// the run without counting toward the listing's no-answer streak.
fresh();
for ( $i = 1; $i <= 7; $i++ ) {
	listing( 2780 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
foreach ( array(
	new WP_Error( 'rate_limited', 'eBay asked for fewer calls (HTTP 429).' ),
	ebay_refused( 'Call usage limit has been reached.', '518' ),
	ebay_refused( 'Too many requests.', '10007' ),
	new WP_Error( 'rate_limited', 'eBay asked for fewer calls (HTTP 429).' ),
) as $limited ) {
	$GLOBALS['API']->answers['ReviseFixedPriceItem'] = $limited;
	run_next();
}
check( 'eBay\'s own limit on calls, four times in a row: paused each time, never counted against the listing', array( run_state()['pause']['why'], run_state()['failed'], get_post_meta( 2786, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['no_answer'] ?? 0, run_state()['cursor'] ), array( 'unreachable', 0, 0, 5 ) );
$GLOBALS['API']->answers['ReviseFixedPriceItem'] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
run_next();
check( '  eBay not reached after them: that one counted', get_post_meta( 2786, TCGiant_Sync_Exporter::RESEND_FAILED_META, true )['no_answer'] ?? 0, 1 );
unset( $GLOBALS['API']->answers['ReviseFixedPriceItem'] );
TCGiant_Sync_Template_Resend::stop();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTOP, EMERGENCY STOP, AND NEVER TWO SENDING AT ONCE\n" . str_repeat( '=', 124 ) . "\n";

fresh();
for ( $i = 1; $i <= 20; $i++ ) {
	listing( 2800 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
add_filter( 'tcgiant_sync_ebay_description', $stop_mid = function ( $html, $product ) {
	if ( 2808 === $product->get_id() ) {
		TCGiant_Sync_Template_Resend::stop( 'stop' );
	}
	return $html;
}, 10, 2 );
run_next();
remove_filter( 'tcgiant_sync_ebay_description', $stop_mid, 10 );
check( 'Stop pressed part way through a batch: it ends after the listing in hand', array( count( rs_calls() ), run_state()['status'], run_state()['end_why'], run_state()['sent'] ), array( 3, 'stopped', 'stop', 3 ) );
check( '  nothing left waiting to run', as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ), array() );
check( '  and run anyway, it sends nothing', array( TCGiant_Sync_Template_Resend::run_batch(), rs_calls() ), array( null, array() ) );

count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
TCGiant_Sync_Importer::$stopped = 0;
$stop = handler( array( admin_object( 'TCGiant_Sync_Admin' ), 'handle_stop_sync' ) );
check( 'Emergency Stop: the import\'s queues and Re-send all, both', array( TCGiant_Sync_Importer::$stopped, run_state()['status'], run_state()['end_why'], as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ) ), array( 1, 'stopped', 'emergency', array() ) );
check( '  said in the log', 1 === preg_match( '/Re-send: Re-send all for Clean, version \d+ ended by Emergency Stop after \d+ sent; \d+ were still to send\./', said() ), true );

// The hold: one sends at a time.
count_now();
TCGiant_Sync_Template_Resend::start( $g );
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'send' ] = array( array( 1, ( time() + 300 ) . '|another-request' ) );
rs_calls();
TCGiant_Sync_Template_Resend::run_batch();
check( 'another request holds the send: this batch sends nothing, and looks again in a minute', array( rs_calls(), run_state()['cursor'], count( as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ) ) ), array( array(), 0, 2 ) );
check( '  Test on 5 is refused meanwhile', TCGiant_Sync_Template_Resend::test( $g )['message'], 'A Re-send all is already going. Stop it first, or wait for it to finish.' );
$GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'send' ] = array( array( 1, ( time() - 1 ) . '|a-request-that-died' ) );
TCGiant_Sync_Template_Resend::run_batch();
check( 'a hold past its time, from a request that died, is taken over', count( rs_calls() ) > 0, true );
check( '  and let go after', isset( $GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'send' ] ), false );
TCGiant_Sync_Template_Resend::stop();

// A Re-send all whose batch died - nothing waiting to carry it on - is
// carried on when the Templates page is drawn; one that moved lately is not.
fresh();
for ( $i = 1; $i <= 8; $i++ ) {
	listing( 2850 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
said();
$GLOBALS['AS']['actions'] = array();
TCGiant_Sync_Template_Resend::screen();
check( 'a Re-send all that moved a moment ago, nothing waiting: left alone', as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ), array() );
$state            = run_state();
$state['touched'] = time() - 700;
update_option( TCGiant_Sync_Template_Resend::STATE_OPTION, $state );
TCGiant_Sync_Template_Resend::screen();
check( '  one that has not moved for longer than a hold lasts: carried on now', array( count( as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ) ), false !== strpos( said(), 'Re-send all had stopped part way with nothing to carry it on' ) ), array( 1, true ) );
TCGiant_Sync_Template_Resend::stop();

// A push of the listing: under way, waiting, or done since the count.
fresh();
for ( $i = 1; $i <= 6; $i++ ) {
	listing( 2900 + $i );
}
count_now();
$GLOBALS['W']['meta'][2901]['_ebay_export_status']        = 'pushing';
$GLOBALS['W']['meta'][2901]['_ebay_export_pushing_since'] = time() - 10;
as_schedule_single_action( time() + 30, TCGiant_Sync_Exporter::PUSH_ACTION, array( 'product_id' => 2902 ), 'tcgiant_sync_group' );
sent_now( wc_get_product( 2903 ), 172800 );
rs_calls();
$t = TCGiant_Sync_Template_Resend::test( $g );
check( 'a push running, one waiting, and one done since the count: left to the push, not sent', array_map( function ( $r ) { return $r['product_id']; }, $t['rows'] ), array( 2904, 2905, 2906 ) );
check( '  the count still counts them; only the send leaves them', TCGiant_Sync_Template_Resend::judge( 2901 )['verdict'], 'send' );
check( '  just before a send, a push under way is "pushing"', TCGiant_Sync_Template_Resend::judge( 2901, true )['reason'], 'pushing' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE HOST'S TIME: A BATCH ENDED PART WAY CARRIES ON, A FAILING DESCRIPTION STOPS NOTHING, AN OLD BATCH WRITES NOTHING\n" . str_repeat( '=', 124 ) . "\n";

fresh();
for ( $i = 1; $i <= 130; $i++ ) {
	listing( 5100 + $i, 0 === $i % 10 ? array( 'stock' => 0 ) : array() );
}
listing( 5231, array( 'modified' => time() ) );
$members_of = function ( $done ) {
	$out = array();
	for ( $n = 0; $n < (int) $done['chunks']; $n++ ) {
		foreach ( (array) get_option( TCGiant_Sync_Template_Resend::IDS_PREFIX . $done['id'] . '_' . $n ) as $group => $ids ) {
			foreach ( $ids as $id => $zero ) {
				$out[ $group ][ $id ] = $zero;
			}
		}
	}
	foreach ( $out as $group => $ids ) {
		ksort( $out[ $group ] );
	}
	return $out;
};
$figures = function ( $done ) {
	return array( $done['looked'], $done['unchanged'], $done['groups'], $done['reasons'] );
};
$whole         = count_now();
$whole_members = $members_of( $whole );
check( 'a count with all the time it needs: 130 to send, one held', array( $whole['groups'][ $g ]['count'], $whole['groups'][ $g ]['last'], $whole['reasons'] ), array( 130, 13, array( 'held' => 1 ) ) );

// The host's time used up at every seventh product.
$asks = 0;
TCGiant_Sync_Runtime::$late = function () use ( &$asks ) {
	return 0 === ++$asks % 7 ? '30 s of this request used, of a 30 s budget' : '';
};
$GLOBALS['AS']['ran'] = array();
$short                = count_now();
TCGiant_Sync_Runtime::$late = '';
check( 'out of time at every seventh product: each batch stops there, and the next carries on', count( array_filter( $GLOBALS['AS']['ran'], function ( $h ) { return TCGiant_Sync_Template_Resend::SCAN_HOOK === $h; } ) ) > 15, true );
check( '  the count the same as one with all the time it needs', array( $figures( $short ), $members_of( $short ) ), array( $figures( $whole ), $whole_members ) );

// A batch the host kills outright, after it kept what it found at 50.
$asks = 0;
TCGiant_Sync_Runtime::$late = function () use ( &$asks ) {
	if ( 70 === ++$asks ) {
		throw new RuntimeException( 'killed by the host' );
	}
	return '';
};
TCGiant_Sync_Template_Resend::queue_scan( 'harness', true );
try {
	as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
} catch ( RuntimeException $e ) {
	$e = null;
}
TCGiant_Sync_Runtime::$late = '';
$count = rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION );
check( 'a batch killed part way: what it found kept at 50, with its place, and nothing waiting', array( $count['doing']['looked'] ?? 0, $count['doing']['cursor'] ?? 0, $count['doing']['chunks'] ?? 0, as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), array( 50, 5150, 1, array() ) );
check( '  its hold let go', isset( $GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'count' ] ), false );
TCGiant_Sync_Template_Resend::screen();
check( '  the Templates page drawn a moment later: left alone, as it may still be running', as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ), array() );
$count['doing']['touched'] = time() - 700;
update_option( TCGiant_Sync_Template_Resend::COUNT_OPTION, $count );
TCGiant_Sync_Template_Resend::screen();
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
$resumed = rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done'];
check( '  drawn after a hold\'s time: carried on from its place, and the count the same as a whole one', array( $figures( $resumed ), $members_of( $resumed ) ), array( $figures( $whole ), $whole_members ) );

// One killed before it kept anything: no count under way and none waiting,
// though a change asked for one. The page starts it, so the box never says
// it is counting when nothing is.
$asks = 0;
TCGiant_Sync_Runtime::$late = function () use ( &$asks ) {
	if ( 3 === ++$asks ) {
		throw new RuntimeException( 'killed by the host' );
	}
	return '';
};
TCGiant_Sync_Template_Resend::queue_scan( 'harness', true );
try {
	as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
} catch ( RuntimeException $e ) {
	$e = null;
}
TCGiant_Sync_Runtime::$late = '';
check( 'a count killed before it kept anything: none under way, none waiting, the last out of date', array( isset( rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['doing'] ), as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ), TCGiant_Sync_Template_Resend::screen()['stale'] ), array( false, array(), true ) );
check( '  the Templates page has started it: one waiting, and "counting" true', array( count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), TCGiant_Sync_Template_Resend::screen()['counting'] ), array( 1, true ) );
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
check( '  and it finishes: up to date, nothing more waiting', array( TCGiant_Sync_Template_Resend::screen()['stale'], as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), array( false, array() ) );
TCGiant_Sync_Template_Resend::screen();
check( '  drawn again: nothing queued, as nothing is wanted', as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ), array() );

// A description that stops with an error - a broken shortcode - is counted
// under its reason, and the count goes on past it.
add_filter( 'tcgiant_sync_ebay_description', $broken = function ( $html, $product ) {
	if ( 5105 === $product->get_id() ) {
		throw new RuntimeException( 'A shortcode stopped with an error.' );
	}
	return $html;
}, 10, 2 );
$done    = count_now();
$at_send = TCGiant_Sync_Template_Resend::judge( 5105, true );
remove_filter( 'tcgiant_sync_ebay_description', $broken, 10 );
check( 'a description that stops with an error: counted under its reason, and the count goes on', array( $done['reasons'], $done['looked'], $done['groups'][ $g ]['count'] ), array( array( 'render_failed' => 1, 'held' => 1 ), $whole['looked'], 129 ) );
check( '  just before a send, the same: left, not sent', array( $at_send['verdict'], $at_send['reason'] ), array( 'left', 'render_failed' ) );

// Re-send all out of time part way through a batch: what it has sent kept,
// the rest of its share of the day's limit given back, and the next batch
// carries on from there.
fresh();
for ( $i = 1; $i <= 20; $i++ ) {
	listing( 5200 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
$item_ids = function ( array $calls ) {
	return array_map( function ( $c ) {
		preg_match( '#<ItemID>(\d+)</ItemID>#', $c[1], $m );
		return $m[1] ?? '';
	}, $calls );
};
$bulk = TCGiant_Sync_Exporter::bulk_pushed_today();
$asks = 0;
TCGiant_Sync_Runtime::$late = function () use ( &$asks ) {
	return ++$asks >= 9 ? '30 s of this request used, of a 30 s budget' : '';
};
run_next();
TCGiant_Sync_Runtime::$late = '';
$first = rs_calls();
$state = run_state();
check( 'Re-send all out of time: it stops, its place and what it sent kept, the rest of its share given back', array( count( $first ), $state['sent'], $state['cursor'], TCGiant_Sync_Exporter::bulk_pushed_today() - $bulk, count( as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ) ) ), array( 4, 4, 9, 4, 1 ) );
run_next();
$all = $item_ids( array_merge( $first, rs_calls() ) );
check( '  the next batch carries on: the fifteen the test did not send, each once', array( count( $all ), count( array_unique( $all ) ), run_state()['sent'], run_state()['status'] ), array( 15, 15, 15, 'done' ) );

// A batch the host kills outright: each listing it sent is kept as sent and
// carried on from, never sent twice.
fresh();
for ( $i = 1; $i <= 20; $i++ ) {
	listing( 5250 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
$asks = 0;
TCGiant_Sync_Runtime::$late = function () use ( &$asks ) {
	if ( 10 === ++$asks ) {
		throw new RuntimeException( 'killed by the host' );
	}
	return '';
};
try {
	run_next();
} catch ( RuntimeException $e ) {
	$e = null;
}
TCGiant_Sync_Runtime::$late = '';
$first = rs_calls();
$state = run_state();
check( 'a Re-send all batch killed part way: each listing it sent counted, its place kept, its hold let go', array( count( $first ), $state['sent'], $state['cursor'], isset( $GLOBALS['wpdb']->rows[ TCGiant_Sync_Template_Resend::LOCK_PREFIX . 'send' ] ) ), array( 5, 5, 10, false ) );
$state['touched'] = time() - 700;
update_option( TCGiant_Sync_Template_Resend::STATE_OPTION, $state );
TCGiant_Sync_Template_Resend::screen();
run_next();
$all = $item_ids( array_merge( $first, rs_calls() ) );
check( '  carried on when the page is drawn: the rest sent, none twice', array( count( $all ), count( array_unique( $all ) ), run_state()['sent'], run_state()['status'] ), array( 15, 15, 15, 'done' ) );

// A request already out of time when its batch starts: each batch still
// counts or sends one, so it gets there, one at a time, and never spins.
fresh();
for ( $i = 1; $i <= 12; $i++ ) {
	listing( 5400 + $i );
}
TCGiant_Sync_Runtime::$late = '30 s of this request used, of a 30 s budget';
$GLOBALS['AS']['ran']       = array();
$done                       = count_now();
$ran                        = count( array_filter( $GLOBALS['AS']['ran'], function ( $h ) { return TCGiant_Sync_Template_Resend::SCAN_HOOK === $h; } ) );
TCGiant_Sync_Runtime::$late = '';
check( 'out of time from the start of every batch: the count still done, one product a batch', array( $done['groups'][ $g ]['count'] ?? 0, $ran ), array( 12, 12 ) );
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
rs_calls();
TCGiant_Sync_Runtime::$late = '30 s of this request used, of a 30 s budget';
run_next();
run_next();
$state = run_state();
TCGiant_Sync_Runtime::$late = '';
check( '  so does Re-send all: one listing a batch', array( $state['cursor'], $state['dropped'] ), array( 2, 2 ) );
TCGiant_Sync_Template_Resend::stop();

// The review's probe: a count begun in a request whose time was already
// spent - WP-Cron's other jobs ran first. It counts one product, and its
// next batch comes a minute on, in another request with time of its own:
// two actions and a handful of rows, not one of each a product.
fresh();
for ( $i = 1; $i <= 120; $i++ ) {
	listing( 5500 + $i );
}
$GLOBALS['request_spent']   = true;
TCGiant_Sync_Runtime::$late = function () {
	return $GLOBALS['request_spent'] ? '30 s of this request used, of a 30 s budget' : '';
};
TCGiant_Sync_Template_Resend::queue_scan( 'harness', true );
$actions  = 0;
$requests = 0;
$waited   = array();
while ( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) && $requests < 300 ) {
	$requests++;
	// One runner request: the actions due now, and no others.
	$actions += as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK );
	foreach ( $GLOBALS['AS']['actions'] as $id => $a ) {
		if ( TCGiant_Sync_Template_Resend::SCAN_HOOK === $a['hook'] ) {
			$waited[] = $a['time'] - time();
			// The next request comes when it is due, with time of its own.
			$GLOBALS['AS']['actions'][ $id ]['time'] = min( $a['time'], time() );
		}
	}
	$GLOBALS['request_spent'] = false;
}
$done = rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done'] ?? array();
check( 'a count begun with its request\'s time spent: one product, then the rest a minute on in another request', array( $done['groups'][ $g ]['count'] ?? 0, $actions, $requests, count( $waited ), ( $waited[0] ?? 0 ) >= 59 ), array( 120, 2, 2, 1, true ) );
check( '  its listings in 4 rows, not 120', $done['chunks'] ?? 0, 4 );

// Re-send all is not changed by it: out of time, a batch sends its one
// listing and the next comes 30 seconds on, as before.
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$GLOBALS['request_spent'] = true;
rs_calls();
run_next();
$GLOBALS['request_spent']   = false;
TCGiant_Sync_Runtime::$late = '';
$run_waits = as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK );
check( '  Re-send all, out of time: one listing looked at, the next batch 30 seconds on', array( run_state()['cursor'], count( $run_waits ), in_array( ( $run_waits[0]['time'] ?? 0 ) - time(), array( 29, 30 ), true ) ), array( 1, 1, true ) );
TCGiant_Sync_Template_Resend::stop();

// Under WP-CLI no host ends the request: the count does not stop for its
// time at all (a process of its own, as WP_CLI is set at load).
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/resend-cli.php' ) . ' 2>&1', $out );
$cli = json_decode( (string) end( $out ), true );
check( 'under WP-CLI, its time spent: 120 counted in one action, the time never asked', array( $cli['count'] ?? null, $cli['actions'] ?? null, $cli['asked'] ?? null ), array( 120, 1, 0 ) );

// Stopped and started again while a batch of the old run is part way: the
// old batch writes nothing over the new run, nor asks for another.
fresh();
for ( $i = 1; $i <= 20; $i++ ) {
	listing( 5300 + $i );
}
count_now();
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$old_run = run_state()['run'];
add_filter( 'tcgiant_sync_ebay_description', $restart = function ( $html, $product ) {
	static $once = false;
	if ( ! $once && 5308 === $product->get_id() ) {
		$once = true;
		TCGiant_Sync_Template_Resend::stop();
		TCGiant_Sync_Template_Resend::start( 't' . $GLOBALS['T'] );
	}
	return $html;
}, 10, 2 );
rs_calls();
run_next();
remove_filter( 'tcgiant_sync_ebay_description', $restart, 10 );
$state = run_state();
check( 'an old batch of a run stopped and started again: the new run\'s state untouched by it', array( $state['run'] !== $old_run, $state['status'], $state['cursor'], $state['sent'], $state['total'] ), array( true, 'running', 0, 0, 20 ) );
check( '  and only the new run\'s batch waiting', count( as_waiting( TCGiant_Sync_Template_Resend::RUN_HOOK ) ), 1 );
check( '  the old batch sent the listing in hand, and no more', count( rs_calls() ), 3 );
run_next();
check( '  the new run: the rest, none twice', array( run_state()['sent'], run_state()['dropped_why'], run_state()['status'] ), array( 12, array( 'unchanged' => 8 ), 'done' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHEN IT COUNTS: AFTER EVERY TEMPLATE-SIDE CHANGE, TEN CHANGES MAKING ONE COUNT\n" . str_repeat( '=', 124 ) . "\n";

fresh();
listing( 3001 );
for ( $i = 0; $i < 10; $i++ ) {
	TCGiant_Sync_Template_Resend::queue_scan( 'harness ' . $i );
}
$waiting = as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK );
check( 'ten changes: one count, a minute on', array( count( $waiting ), $waiting[0]['time'] - time() >= 59 ), array( 1, true ) );
foreach ( $GLOBALS['AS']['actions'] as $id => $a ) {
	$GLOBALS['AS']['actions'][ $id ]['time'] = time() + 5;
}
TCGiant_Sync_Template_Resend::queue_scan( 'harness' );
$waiting = as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK );
check( '  another change does not put it off: changes coming all the time still get counted', array( count( $waiting ), $waiting[0]['time'] - time() <= 5 ), array( 1, true ) );
TCGiant_Sync_Template_Resend::queue_scan( 'Count again', true );
$waiting = as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK );
check( 'Count again: still one, now', array( count( $waiting ), $waiting[0]['time'] <= time() ), array( 1, true ) );
// A change while a count is under way: it counts again at its end.
fresh();
for ( $i = 1; $i <= 250; $i++ ) {
	listing( 3100 + $i );
}
TCGiant_Sync_Template_Resend::queue_scan( 'harness', true );
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true, 1 );
check( 'a count of 250 is two batches: the first leaves its place', array( rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['doing']['cursor'], count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ) ), array( 3300, 1 ) );
TCGiant_Sync_Template_Resend::queue_scan( 'harness' );
check( '  a change meanwhile, in the same second: queues nothing more', count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), 1 );
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true, 1 );
check( '  the count done: 250 changed, and another count waiting for the change', array( rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done']['groups'][ $g ]['count'], count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ) ), array( 250, 1 ) );
$old_id = rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done']['id'];
check( '  its listings in two rows', array( is_array( get_option( TCGiant_Sync_Template_Resend::IDS_PREFIX . $old_id . '_0' ) ), is_array( get_option( TCGiant_Sync_Template_Resend::IDS_PREFIX . $old_id . '_1' ) ) ), array( true, true ) );
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
check( '  counted again: the last count\'s rows deleted', array( get_option( TCGiant_Sync_Template_Resend::IDS_PREFIX . $old_id . '_0', 'gone' ), get_option( TCGiant_Sync_Template_Resend::IDS_PREFIX . $old_id . '_1', 'gone' ) ), array( 'gone', 'gone' ) );
// A count whose batch died: carried on.
$count = rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION );
$count['doing'] = array( 'id' => 'dead', 'started' => time() - 3000, 'touched' => time() - 700, 'cursor' => 0, 'chunks' => 0, 'looked' => 0, 'unchanged' => 0, 'missed' => 0, 'groups' => array(), 'reasons' => array() );
update_option( TCGiant_Sync_Template_Resend::COUNT_OPTION, $count );
$GLOBALS['AS']['actions'] = array();
TCGiant_Sync_Template_Resend::queue_scan( 'harness' );
check( 'a count whose batch died, nothing waiting: carried on now', count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), 1 );

// Every template-side change, through its own handler.
$admin = TCGiant_Sync_Template_Admin::instance();
$queued = function () {
	$n                        = count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) );
	$GLOBALS['AS']['actions'] = array();
	return $n;
};
$GLOBALS['AS']['actions'] = array();
$pointer = TCGiant_Sync_Template_Resend::pointer();
$r = handler( array( $admin, 'handle_save' ), array( 'template_id' => $T, 'name' => 'Clean', 'status' => 'publish', 'html' => '<h1>{{title}}</h1>{{{description}}}', 'css' => '' ) );
check( 'a template saved: a count queued; the notice points to the box', array( $queued(), substr( $r[2]['text'], -strlen( $pointer ) ) ), array( 1, $pointer ) );
$r = handler( array( $admin, 'handle_action' ), array( 'do' => 'restore', 'id' => $T, 'version' => 1 ) );
check( 'a version restored: a count; the box pointed to', array( $queued(), false !== strpos( $r[2]['text'], $pointer ) ), array( 1, true ) );
$r = handler( array( $admin, 'handle_action' ), array( 'do' => 'duplicate', 'id' => $T ) );
check( 'a template copied: a count', $queued(), 1 );
$copy = (int) preg_replace( '/.*id=(\d+).*/', '$1', (string) $r[1] );
$r = handler( array( $admin, 'handle_action' ), array( 'do' => 'delete', 'id' => $copy ) );
check( 'a template deleted: a count', $queued(), 1 );
$r = handler( array( $admin, 'handle_action' ), array( 'do' => 'default', 'id' => $T ) );
check( 'set as default: a count; the notice still says nothing is sent now, and points to the box', array( $queued(), false !== strpos( $r[2]['text'], 'nothing is sent to eBay now.' ), false !== strpos( $r[2]['text'], $pointer ) ), array( 1, true, true ) );
$r = handler( array( $admin, 'handle_block' ), array( 'slug' => 'footer', 'label' => 'Footer', 'html' => array( 'en' => 'Thank you.' ) ) );
check( 'a block saved: a count; pointed to', array( $queued(), false !== strpos( $r[2]['text'], $pointer ) ), array( 1, true ) );
$r = handler( array( $admin, 'handle_block' ), array( 'slug' => 'footer', 'delete' => 1 ) );
check( 'a block deleted: a count', $queued(), 1 );
$r = handler( array( $admin, 'handle_settings' ), array( 'tpl' => array( 'default_template_id' => $T ) ) );
check( 'Template settings saved: a count; "Nothing has been sent to eBay" kept, and the pointer', array( $queued(), 0 === strpos( $r[2]['text'], 'Template settings saved. Nothing has been sent to eBay: a listing gets its template on its next push. ' . $pointer ) ), array( 1, true ) );
check( 'the bulk "Set eBay listing template": a count', array( (bool) $admin->handle_bulk_actions( 'edit.php', TCGiant_Sync_Template_Admin::BULK_PREFIX . $T, array( 3001 ) ), $queued() ), array( true, 1 ) );
$_REQUEST = array( 'tcgiant_tpl_set' => 1, 'tcgiant_tpl_name' => $T );
ob_start();
$admin->bulk_notice();
$notice = ob_get_clean();
check( '  its notice: nothing has been sent, and the pointer', array( false !== strpos( $notice, 'Nothing has been sent to eBay: each listing gets its template on its next push.' ), false !== strpos( $notice, esc_html( $pointer ) ) ), array( true, true ) );
check( 'a product\'s own choice, changed: a count', array( TCGiant_Sync_Template_Admin::save_product_fields( 3001, array( '_tcgiant_tpl_fields' => 1, '_tcgiant_tpl_override' => 'none' ) ), $queued() ), array( true, 1 ) );
check( '  saved again, the same choice: none', array( TCGiant_Sync_Template_Admin::save_product_fields( 3001, array( '_tcgiant_tpl_fields' => 1, '_tcgiant_tpl_override' => 'none' ) ), $queued() ), array( true, 0 ) );
$GLOBALS['API']->policies['return'] = array( array( 'returnPolicyId' => 'RP1', 'name' => '30 days', 'returnsAccepted' => true, 'returnPeriod' => array( 'value' => 30, 'unit' => 'DAY' ) ) );
$settings_admin = admin_object( 'TCGiant_Sync_Admin' );
handler( array( $settings_admin, 'ajax_fetch_policies' ) );
check( 'Fetch eBay Policies, and the policies changed: a count', $queued(), 1 );
handler( array( $settings_admin, 'ajax_fetch_policies' ) );
check( '  fetched again, the same: none', $queued(), 0 );


// A shop with no published template, that never sent one and never
// counted: nothing at all from a change - no count queued, no option
// written, no line in the log. Its templates are drafts, as the starters
// are on every shop that has opened wp-admin since 3.27.8.
fresh();
$hidden = array();
foreach ( $GLOBALS['W']['posts'] as $id => $post ) {
	if ( TCGiant_Sync_Templates::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ) {
		$hidden[ $id ]     = $post->post_status;
		$post->post_status = 'draft';
	}
}
check( 'a shop whose templates are all drafts, as the starters are: it has templates', count( TCGiant_Sync_Templates::all() ) > 0, true );
listing( 3051 );
said();
$GLOBALS['AS']['actions'] = array();
TCGiant_Sync_Template_Resend::queue_scan( 'Template settings saved' );
TCGiant_Sync_Template_Resend::screen();
check( '  none published and none ever sent: no count, no option, no line in the log, even with the page drawn', array( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ), get_option( TCGiant_Sync_Template_Resend::COUNT_OPTION, 'none' ), said() ), array( array(), 'none', '' ) );
$GLOBALS['W']['meta'][3051]['_tcgiant_tpl_id'] = '7';
TCGiant_Sync_Template_Resend::queue_scan( 'Template settings saved' );
check( '  one that was once sent a template: counted, so it can go back to the plain description', count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), 1 );
unset( $GLOBALS['W']['meta'][3051]['_tcgiant_tpl_id'] );
delete_option( TCGiant_Sync_Template_Resend::COUNT_OPTION );
$GLOBALS['AS']['actions'] = array();
TCGiant_Sync_Template_Resend::queue_scan( 'Count again', true );
check( '  Count again, pressed: counted all the same', count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), 1 );
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
check( '    and it finds nothing to send', rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION )['done']['groups'] ?? null, array() );
$GLOBALS['AS']['actions'] = array();
TCGiant_Sync_Template_Resend::queue_scan( 'a template deleted' );
check( '  and a shop that has counted before: counted again, so a deleted template\'s group goes', count( as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK ) ), 1 );
$GLOBALS['AS']['actions'] = array();
foreach ( $hidden as $id => $status ) {
	$GLOBALS['W']['posts'][ $id ]->post_status = $status;
}
// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE BOX'S BUTTONS: MANAGE_OPTIONS AND A NONCE\n" . str_repeat( '=', 124 ) . "\n";

fresh();
listing( 3201 );
$GLOBALS['nonce_ok'] = false;
$r = handler( array( $admin, 'handle_resend' ), array( 'do' => 'count' ) );
check( 'a bad nonce: refused, nothing queued', array( $r[0], $queued() ), array( 'died', 0 ) );
$GLOBALS['nonce_ok'] = true;
$GLOBALS['W']['user_can'] = false;
$r = handler( array( $admin, 'handle_resend' ), array( 'do' => 'count' ) );
check( 'without manage_options: refused, nothing queued', array( $r[0], $queued() ), array( 'died', 0 ) );
$GLOBALS['W']['user_can'] = true;
$r = handler( array( $admin, 'handle_resend' ), array( 'do' => 'count' ) );
check( 'Count again: its own nonce, a count now, back to the box', array( $GLOBALS['nonce_action'], $r[0], false !== strpos( (string) $r[1], '#tc-resend' ), as_waiting( TCGiant_Sync_Template_Resend::SCAN_HOOK )[0]['time'] <= time() ), array( 'tcgiant_tpl_resend', 'redirect', true, true ) );
as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK, true );
$r = handler( array( $admin, 'handle_resend' ), array( 'do' => 'start', 'group' => $g ) );
check( 'Re-send all before a test, from the box: refused, said', array( $r[2]['type'], $r[2]['text'] ), array( 'error', 'Test on 5 first: Re-send all unlocks once a test of this template, as it is now, comes back clean.' ) );
$r = handler( array( $admin, 'handle_resend' ), array( 'do' => 'test', 'group' => $g ) );
check( 'Test on 5, from the box', array( $r[2]['type'], count( rs_calls() ) ), array( 'success', 1 ) );
$r = handler( array( $admin, 'handle_resend' ), array( 'do' => 'stop' ) );
check( 'Stop with nothing going: said', $r[2]['text'], 'No Re-send all was going.' );
check( 'the view\'s every form carries the nonce and its action', array( substr_count( $view = ( function () { ob_start(); include TCGIANT_SYNC_PATH . 'admin/views/template-resend.php'; return ob_get_clean(); } )(), 'name="_wpnonce" value="nonce:tcgiant_tpl_resend"' ), substr_count( $view, 'name="action" value="tcgiant_tpl_resend"' ) ), array( 3, 3 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE BOX\n" . str_repeat( '=', 124 ) . "\n";

/** The box, as the Templates page draws it. */
function box() {
	ob_start();
	include TCGIANT_SYNC_PATH . 'admin/views/template-resend.php';
	return preg_replace( '/\s+/', ' ', ob_get_clean() );
}

fresh();
check( 'the box is on the Templates page, below the templates', false !== strpos( (string) file_get_contents( TCGIANT_SYNC_PATH . 'admin/views/template-list.php' ), "\tinclude TCGIANT_SYNC_PATH . 'admin/views/template-resend.php';" ), true );
check( 'never counted: says so, and offers Count again', array( false !== strpos( box(), 'Not counted yet. Press Count again to see which listings would change.' ), false !== strpos( box(), '>Count again</button>' ) ), array( true, true ) );
for ( $i = 1; $i <= 7; $i++ ) {
	listing( 3300 + $i, 7 === $i ? array( 'stock' => 0 ) : array() );
}
listing( 3308, array(), array( '_ebay_listing_type' => 'Chinese' ) );
listing( 3309, array( 'modified' => time() ) );
listing( 3300, array(), array( '_ebay_item_id' => null ) );
count_now();
$box = box();
check( 'the count and what it costs', false !== strpos( $box, '<strong> 7 listings would change. Each costs 1 eBay call. </strong>' ), true );
check( '  the group, its template and version, its listings, one at quantity 0', array( false !== strpos( $box, '<strong>Clean, version ' . TCGiant_Sync_Templates::get( $T )['version'] . '</strong>' ), false !== strpos( $box, '1 at quantity 0, sent last' ) ), array( true, true ) );
check( '  Re-send all disabled: "Test on 5 first."', 1 === preg_match( '/<button type="submit" class="tc-button " disabled>Re-send all<\/button> <span class="tc-hint tc-resend-why">Test on 5 first\.<\/span>/', $box ), true );
check( '  what is left out, with its count and why', array( false !== strpos( $box, '<strong>1</strong> Auctions: eBay restricts changes once there are bids, so they get the template at their next push.' ), false !== strpos( $box, '<strong>1</strong> Held for a push:' ) ), array( true, true ) );
check( '  Not on eBay last, though counted first, and quieter', 1 === preg_match( '#<li class="tc-resend-quiet"><strong>1</strong> Not on eBay: [^<]*</li> </ul>#', $box ), true );
check( '  how many are held only because they were saved since, and that a push or a bulk push sends the template too', false !== strpos( $box, '1 listing is held only because its product was saved since its listing was last sent: a sale, a stock change or a sync is enough. A push sends it the template with the rest of the listing, and so does a bulk push (Products, Bulk actions, Push to eBay).' ), true );
check( '  that only the description goes, and the rest still needs a push', false !== strpos( $box, 'Re-send sends them the new description and nothing else: one eBay call a listing. Changes to the title, price, photos or item specifics still need a push, and a push - or a bulk push - sends the template too.' ), true );
check( '  the day\'s bulk pushes, and that re-sends count against them', false !== strpos( $box, 'Bulk pushes today: 0 of 2,000. Re-sends count against this limit, as bulk pushes do, and go 166 at most in any two hours.' ), true );
TCGiant_Sync_Template_Resend::test( $g );
$box = box();
check( 'after a clean test: each listing with its eBay link and outcome, and Re-send all enabled', array( false !== strpos( $box, 'Tested ' ), false !== strpos( $box, ': 5 of 5 OK.' ), false !== strpos( $box, '<a href="https://www.ebay.com/itm/110000003301" target="_blank" rel="noopener">110000003301 &nearr;</a>' ), false !== strpos( $box, '<button type="submit" class="tc-button ">Re-send all</button>' ) ), array( true, true, true, true ) );
TCGiant_Sync_Template_Resend::start( $g );
own_calls( 500 );
run_next();
$box = box();
check( 'paused, in plain words, with when it tries again', 1 === preg_match( '/Paused\. This site has used half of its own eBay calls for today\. The rest are kept for stock and orders\. It tries again at \d+ \w+ \d{4}, 00:05\./', $box ), true );
check( '  sent, still to send, refused, waiting for a later day, needing attention', false !== strpos( $box, 'Sent: 0. Still to send: 7. Refused by eBay: 0, of which 0 wait to be tried again on a later day and 0 need attention.' ), true );
check( '  nothing dropped yet, so no line about it', false === strpos( $box, 'Dropped because' ), true );
check( '  and a Stop button', false !== strpos( $box, '>Stop</button>' ), true );
own_calls( 0 );
TCGiant_Sync_Template_Resend::stop();
check( 'stopped: the last one said', false !== strpos( box(), 'The last Re-send all, for Clean, version ' . TCGiant_Sync_Templates::get( $T )['version'] . ', was stopped on ' ), true );
TCGiant_Sync_OAuth::$connected = false;
check( 'not connected: said, and the buttons wait', array( false !== strpos( box(), 'This site is not connected to eBay, so nothing can be sent.' ), false !== strpos( box(), 'Nothing can be sent from this site now.' ) ), array( true, true ) );
TCGiant_Sync_OAuth::$connected = true;

// A staging copy, in a process of its own (its constant is set at load).
$out = array();
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/resend-staging.php' ) . ' 2>&1', $out );
$staging = json_decode( (string) end( $out ), true );
check( 'a staging copy: Test on 5 refused, with why', is_array( $staging ) && false !== strpos( (string) $staging['test'], 'this site looks like a staging or development copy' ), true );
check( '  Re-send all paused there, nothing sent', array( $staging['why'] ?? null, $staging['calls'] ?? null ), array( 'staging', 0 ) );
check( '  and the box says why nothing goes', ! empty( $staging['box'] ), true );

// The mutation run leaves the 20,000 out, but for the mutants of storage.
if ( getenv( 'TCG_RESEND_QUICK' ) ) {
	done();
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n20,000 LISTINGS: FOUR HUNDRED SMALL ROWS, NONE AUTOLOADED\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$GLOBALS['W']['autoload'] = array();
for ( $i = 1; $i <= 20000; $i++ ) {
	listing( 100000 + $i );
	$GLOBALS['W']['item_index'][ (string) ( 110000000000 + 100000 + $i ) ] = array( (string) ( 100000 + $i ) );
}
$started = microtime( true );
$done    = count_now();
check( 'counted, in 100 batches of 200, kept every 50', array( $done['groups'][ $g ]['count'], $done['chunks'], count( array_filter( $GLOBALS['AS']['ran'], function ( $h ) { return TCGiant_Sync_Template_Resend::SCAN_HOOK === $h; } ) ) >= 100 ), array( 20000, 400, true ) );
TCGiant_Sync_Template_Resend::test( $g );
TCGiant_Sync_Template_Resend::start( $g );
$mine    = array_filter( array_keys( $GLOBALS['W']['options'] ), function ( $n ) { return 0 === strpos( $n, 'tcgiant_sync_tpl_resend' ); } );
$largest = 0;
foreach ( $mine as $name ) {
	$largest = max( $largest, strlen( serialize( $GLOBALS['W']['options'][ $name ] ) ) );
}
check( 'every row it wrote: not autoloaded', array_values( array_unique( array_map( function ( $n ) { return $GLOBALS['W']['autoload'][ $n ] ?? 'unset'; }, $mine ) ) ), array( 'no' ) );
check( '  the count itself small (under 2 KB), the listings in 400 rows of 50, the queue in 40', array( strlen( serialize( rs_option( TCGiant_Sync_Template_Resend::COUNT_OPTION ) ) ) < 2048, count( array_filter( $mine, function ( $n ) { return 0 === strpos( $n, TCGiant_Sync_Template_Resend::IDS_PREFIX ); } ) ), count( array_filter( $mine, function ( $n ) { return 0 === strpos( $n, TCGiant_Sync_Template_Resend::QUEUE_PREFIX ); } ) ) ), array( true, 400, 40 ) );
check( '  no row over 12 KB', $largest < 12288, true );
printf( "  (counted 20,000 in %.1f s; largest row %d bytes)\n", microtime( true ) - $started, $largest );
TCGiant_Sync_Template_Resend::stop();

done();
