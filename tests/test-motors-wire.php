<?php
/**
 * Harness: eBay Motors at the wire (3.28.9).
 *
 * The real TCGiant_Sync_API and TCGiant_Sync_Motors, with eBay played where
 * the API class meets it: wp_remote_request(). What is checked is what would
 * go out - the X-EBAY-API-SITEID header of each Trading call, the URL of each
 * Taxonomy and Metadata read - and what each read is kept under.
 *
 * - A Trading call goes with the shop's own site, 0 for eBay US, unless it is
 *   made inside with_site(), which a push of an eBay Motors listing uses: then
 *   with 100, and with 0 again after, however the call inside ended. The
 *   shop's marketplace setting is never touched.
 * - Item specifics are read from, and kept under, the tree asked for: tree 0
 *   and tree 100 are two entries for the same category ID and never stand in
 *   for each other. So are a Motors category's item conditions
 *   (EBAY_MOTORS_US) and what is learnt of its tree.
 * - Which tree a category is in costs nothing for one already learnt, and at
 *   most the item specifics of tree 0 for an ordinary one, which the push
 *   reads anyway and the API keeps for 7 days. Only eBay's 62005 there asks
 *   tree 100, once a category: its node, and its suggestions when the node's
 *   parent does not say its branch.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );
$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-96s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}

define( 'ABSPATH', $ROOT . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'TCGIANT_SYNC_IS_STAGING', false );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_rand( $min = 0, $max = 0 ) { return $min; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['OPTIONS'][ $name ] ); return true; }
function get_transient( $name ) { return $GLOBALS['TRANSIENTS'][ $name ] ?? false; }
function set_transient( $name, $value, $ttl = 0 ) { $GLOBALS['TRANSIENTS'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['TRANSIENTS'][ $name ] ); return true; }
// WordPress's own: the values go into the URL as they are given.
function add_query_arg( $args, $url ) {
	$pairs = array();
	foreach ( $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}
// A live shop, by wp-config (TCGiant_Sync_Environment).
function wp_load_alloptions() { return array( 'home' => 'https://shop.example', 'siteurl' => 'https://shop.example' ); }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

/** The day's call counter, as the options table keeps it. */
class Wire_Wpdb {
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var( $q ) { return (string) ( $GLOBALS['COUNTED'] ?? 0 ); }
	public function query( $q ) {
		if ( false !== strpos( (string) $q[0], 'ON DUPLICATE KEY UPDATE' ) ) {
			$GLOBALS['COUNTED'] = ( $GLOBALS['COUNTED'] ?? 0 ) + 1;
		}
		return 1;
	}
	public function get_results( $q ) { return array(); }
}
$GLOBALS['wpdb'] = new Wire_Wpdb();

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return $GLOBALS['SETTINGS']; }
	public function get_access_token() { return 'wire-token'; }
	public function refresh_access_token() { return false; }
}

/**
 * eBay, played at the wire: every request recorded (its URL, its site header,
 * its call name) and answered from $REPLIES - by Trading call name, or by the
 * first key a REST URL contains - in eBay's own form.
 */
function wp_remote_request( $url, $args ) {
	$GLOBALS['WIRE'][] = array(
		'url'  => $url,
		'site' => $args['headers']['X-EBAY-API-SITEID'] ?? null,
		'call' => $args['headers']['X-EBAY-API-CALL-NAME'] ?? '',
	);
	$call = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	if ( '' !== $call ) {
		$inner = $GLOBALS['REPLIES'][ $call ] ?? '<Ack>Success</Ack><ItemID>110000000001</ItemID>';
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents">' . $inner . '</' . $call . 'Response>' );
	}
	foreach ( $GLOBALS['REPLIES'] as $needle => $reply ) {
		if ( false !== strpos( $url, $needle ) ) {
			return array( 'headers' => array(), 'response' => array( 'code' => $reply[0] ), 'body' => json_encode( $reply[1] ) );
		}
	}
	return array( 'headers' => array(), 'response' => array( 'code' => 404 ), 'body' => '{"errors":[{"errorId":1}]}' );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }

require $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-motors.php';

/** A shop on eBay US with nothing learnt and nothing on the wire. */
function fresh( $marketplace = 'EBAY_US' ) {
	$GLOBALS['OPTIONS']    = array();
	$GLOBALS['TRANSIENTS'] = array();
	$GLOBALS['WIRE']       = array();
	$GLOBALS['REPLIES']    = array();
	$GLOBALS['LOG']        = array();
	$GLOBALS['COUNTED']    = 0;
	$GLOBALS['SETTINGS']   = array( 'marketplace' => $marketplace );
}
/** The site header of each Trading call on the wire. */
function sites() {
	$out = array();
	foreach ( $GLOBALS['WIRE'] as $w ) {
		if ( '' !== $w['call'] ) {
			$out[] = $w['call'] . '@' . $w['site'];
		}
	}
	return $out;
}
/** The REST URLs on the wire, without the host. */
function urls() {
	$out = array();
	foreach ( $GLOBALS['WIRE'] as $w ) {
		if ( '' === $w['call'] ) {
			$out[] = str_replace( 'https://api.ebay.com/', '', $w['url'] );
		}
	}
	return $out;
}
$not_in_tree = array( 400, array( 'errors' => array( array( 'errorId' => 62005, 'domain' => 'API_TAXONOMY', 'message' => 'The specified category ID does not belong to specified category tree.' ) ) ) );

$api = TCGiant_Sync_API::instance();

echo "\nTHE SITE A TRADING CALL GOES WITH\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$api->trading_api_request( 'GetItem', '<ItemID>1</ItemID>' );
$api->with_site( TCGiant_Sync_Motors::SITE_ID, function () use ( $api ) {
	return $api->trading_api_request( 'AddFixedPriceItem', '<Item></Item>' );
} );
$api->trading_api_request( 'ReviseFixedPriceItem', '<Item></Item>' );
check( 'the shop\'s own site, 0; inside with_site(), 100; after it, 0 again', sites(), array( 'GetItem@0', 'AddFixedPriceItem@100', 'ReviseFixedPriceItem@0' ) );
check( '  each counted against the day, as any call', $GLOBALS['COUNTED'], 3 );
check( '  the shop\'s marketplace setting untouched', $GLOBALS['SETTINGS'], array( 'marketplace' => 'EBAY_US' ) );
check( '  what with_site() gives back is the call\'s answer', $api->with_site( '100', function () { return 'answer'; } ), 'answer' );

fresh();
try {
	$api->with_site( '100', function () { throw new RuntimeException( 'killed' ); } );
} catch ( RuntimeException $e ) {
	$GLOBALS['LOG'][] = array( 'caught', $e->getMessage() );
}
$api->trading_api_request( 'EndItem', '<ItemID>1</ItemID>' );
check( 'a call inside that throws: the next call goes with the shop\'s own site', sites(), array( 'EndItem@0' ) );
check( '  request_site_id() says the same', $api->request_site_id(), '0' );

fresh();
$api->with_site( '100', function () use ( $api ) {
	$api->with_site( '3', function () use ( $api ) { return $api->trading_api_request( 'GetItem', '' ); } );
	return $api->trading_api_request( 'RelistFixedPriceItem', '' );
} );
check( 'nested: each call with the site of its own with_site()', sites(), array( 'GetItem@3', 'RelistFixedPriceItem@100' ) );

fresh( 'EBAY_GB' );
$api->trading_api_request( 'GetItem', '' );
check( 'a shop on eBay UK: 3, as before', sites(), array( 'GetItem@3' ) );

echo "\nITEM SPECIFICS AND CONDITIONS, BY TREE\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/100/get_item_aspects_for_category' => array( 200, array( 'aspects' => array( array( 'localizedAspectName' => 'Manufacturer Part Number', 'aspectConstraint' => array( 'aspectRequired' => true ) ) ) ) ),
	'category_tree/0/get_item_aspects_for_category'   => array( 200, array( 'aspects' => array( array( 'localizedAspectName' => 'Brand', 'aspectConstraint' => array( 'aspectRequired' => true ) ) ) ) ),
);
$in_100 = $api->get_category_aspects_in_tree( '33564', '100' );
$in_0   = $api->get_category_aspects( '33564' );
check( 'the same ID in tree 100 and tree 0: each tree\'s own aspects', array( $in_100[0]['name'] ?? null, $in_0[0]['name'] ?? null ), array( 'Manufacturer Part Number', 'Brand' ) );
check( '  asked of each tree', urls(), array( 'commerce/taxonomy/v1/category_tree/100/get_item_aspects_for_category?category_id=33564', 'commerce/taxonomy/v1/category_tree/0/get_item_aspects_for_category?category_id=33564' ) );
check( '  kept apart, under the tree and the category', array_keys( $GLOBALS['TRANSIENTS'] ), array( 'tcgiant_aspects_100_33564', 'tcgiant_aspects_0_33564' ) );
$GLOBALS['WIRE'] = array();
$api->get_category_aspects( '33564' );
$api->get_category_aspects_in_tree( '33564', '100' );
check( '  read again: from what was kept, nothing asked', array( urls(), $api->get_category_aspects( '33564' )[0]['name'] ?? null ), array( array(), 'Brand' ) );

fresh();
$GLOBALS['REPLIES'] = array(
	'get_item_condition_policies' => array( 200, array( 'itemConditionPolicies' => array( array( 'categoryId' => '35560', 'categoryTreeId' => '100', 'itemConditionRequired' => true, 'itemConditions' => array( array( 'conditionId' => '1000', 'conditionDescription' => 'New' ), array( 'conditionId' => '3000', 'conditionDescription' => 'Used' ), array( 'conditionId' => '7000', 'conditionDescription' => 'For parts or not working' ) ) ) ) ) ),
);
$conds = TCGiant_Sync_Motors::conditions( '35560' );
check( 'a Motors category\'s conditions: from EBAY_MOTORS_US, with eBay\'s filter, URL-encoded', urls(), array( 'sell/metadata/v1/marketplace/EBAY_MOTORS_US/get_item_condition_policies?filter=categoryIds%3A%7B35560%7D' ) );
check( '  the ones eBay takes', $conds, array( 'required' => true, 'conditions' => array( '1000' => 'New', '3000' => 'Used', '7000' => 'For parts or not working' ) ) );
check( '  kept under tree 100 and the category', array_keys( $GLOBALS['TRANSIENTS'] ), array( 'tcgiant_conds_100_35560' ) );
$GLOBALS['WIRE'] = array();
TCGiant_Sync_Motors::conditions( '35560' );
check( '  read again: nothing asked', urls(), array() );

fresh();
$GLOBALS['REPLIES'] = array( 'get_item_condition_policies' => array( 200, array( 'warnings' => array( array( 'errorId' => 46003 ) ) ) ) );
$none = TCGiant_Sync_Motors::conditions( '35560' );
check( 'eBay\'s 46003 (no policy for the category): an error, and nothing kept', array( is_wp_error( $none ) ? $none->get_error_code() : $none, $GLOBALS['TRANSIENTS'] ), array( 'no_condition_policy', array() ) );

// eBay's filter gives "the policies for that parent node, plus the policies
// for any leaf nodes below that parent node": the category's own is taken.
fresh();
$GLOBALS['REPLIES'] = array( 'get_item_condition_policies' => array( 200, array( 'itemConditionPolicies' => array(
	array( 'categoryId' => '35500', 'itemConditions' => array( array( 'conditionId' => '1000', 'conditionDescription' => 'New' ) ) ),
	array( 'categoryId' => '35560', 'itemConditions' => array( array( 'conditionId' => '3000', 'conditionDescription' => 'Used' ) ) ),
) ) ) );
check( 'an answer with other categories\' policies in it: the category\'s own', TCGiant_Sync_Motors::conditions( '35560' )['conditions'] ?? null, array( '3000' => 'Used' ) );

echo "\nWHICH TREE A CATEGORY IS IN, AND WHAT IT COSTS\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => array( 200, array( 'aspects' => array() ) ),
);
$found = TCGiant_Sync_Motors::resolve( '33564' );
check( 'an ordinary category: tree 0\'s item specifics answer, and that is all', array( $found['tree'], urls() ), array( '', array( 'commerce/taxonomy/v1/category_tree/0/get_item_aspects_for_category?category_id=33564' ) ) );
check( '  nothing learnt of it', get_option( TCGiant_Sync_Motors::OPTION, 'none' ), 'none' );
$GLOBALS['WIRE'] = array();
TCGiant_Sync_Motors::resolve( '33564' );
check( '  asked again within the week: nothing on the wire (the item specifics are kept)', urls(), array() );

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
	'category_tree/100/get_category_subtree'        => array( 200, array( 'categoryTreeId' => '100', 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => '35560', 'categoryName' => 'Vintage Motorcycle Parts' ), 'categoryTreeNodeLevel' => 4, 'leafCategoryTreeNode' => true, 'parentCategoryTreeNodeHref' => 'https://api.ebay.com/commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=10063' ) ) ),
	'category_tree/100/get_category_suggestions'    => array( 200, array( 'categorySuggestions' => array(
		array( 'category' => array( 'categoryId' => '35561', 'categoryName' => 'Other' ), 'categoryTreeNodeAncestors' => array( array( 'categoryId' => '10063', 'categoryName' => 'Motorcycle & Scooter Parts' ), array( 'categoryId' => '6028', 'categoryName' => 'Parts & Accessories' ), array( 'categoryId' => '6000', 'categoryName' => 'eBay Motors' ) ) ),
		array( 'category' => array( 'categoryId' => '35560', 'categoryName' => 'Vintage Motorcycle Parts' ), 'categoryTreeNodeAncestors' => array( array( 'categoryId' => '10063', 'categoryName' => 'Motorcycle & Scooter Parts' ), array( 'categoryId' => '6028', 'categoryName' => 'Parts & Accessories' ), array( 'categoryId' => '6000', 'categoryName' => 'eBay Motors' ) ) ),
	) ) ),
);
$found = TCGiant_Sync_Motors::resolve( '35560' );
check( 'the customer\'s 35560: 62005 in tree 0, its node in tree 100, its suggestions say Parts & Accessories', array( $found['tree'], $found['branch'] ), array( '100', 'parts' ) );
check( '  three reads, once', urls(), array(
	'commerce/taxonomy/v1/category_tree/0/get_item_aspects_for_category?category_id=35560',
	'commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=35560',
	'commerce/taxonomy/v1/category_tree/100/get_category_suggestions?q=Vintage%20Motorcycle%20Parts',
) );
check( '  learnt by tree: the category, and the parent its suggestion named', array( TCGiant_Sync_Motors::record( '35560' ), TCGiant_Sync_Motors::record( '10063' ), array_keys( get_option( TCGiant_Sync_Motors::OPTION ) ) ), array( array( 'b' => 'parts', 'p' => '10063' ), array( 'b' => 'parts', 'p' => '6028' ), array( '100:10063', '100:35560' ) ) );
check( '  said, as an eBay Motors category', $found['said'], 'Category 35560 is an eBay Motors category (eBay Motors Parts & Accessories): pushes list it on eBay Motors.' );
$GLOBALS['WIRE'] = array();
check( '  asked again: from what was learnt, nothing on the wire', array( TCGiant_Sync_Motors::resolve( '35560' )['branch'], urls() ), array( 'parts', array() ) );

// A sibling under the parent learnt: its node's parent says the branch.
$GLOBALS['REPLIES']['category_tree/100/get_category_subtree'] = array( 200, array( 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => '35562', 'categoryName' => 'Seats' ), 'leafCategoryTreeNode' => true, 'parentCategoryTreeNodeHref' => 'https://api.ebay.com/commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=10063' ) ) );
$GLOBALS['WIRE'] = array();
$found = TCGiant_Sync_Motors::resolve( '35562' );
check( 'a sibling: tree 0 and its node, and its parent learnt says the branch - no suggestions asked', array( $found['branch'], count( urls() ) ), array( 'parts', 2 ) );

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
	'category_tree/100/get_category_subtree'        => array( 200, array( 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => '6472', 'categoryName' => 'Triumph' ), 'leafCategoryTreeNode' => true, 'parentCategoryTreeNodeHref' => '/commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=6024' ) ) ),
);
$found = TCGiant_Sync_Motors::resolve( '6472' );
check( 'a whole motorcycle (under 6024 Motorcycles): a vehicle category, from its node alone', array( $found['tree'], $found['branch'], count( urls() ) ), array( '100', 'vehicles', 2 ) );

// The branch by its ancestors' IDs, whatever eBay names them ("Parts, Tools &
// Accessories", "Motorcycles & Scooters"): a name that changes does not move
// a category out of its branch.
foreach ( array( array( '35570', '6028', 'Parts, Tools & Accessories', 'parts' ), array( '6480', '6024', 'Motorcycles & Scooters', 'vehicles' ) ) as $case ) {
	fresh();
	$GLOBALS['REPLIES'] = array(
		'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
		'category_tree/100/get_category_subtree'        => array( 200, array( 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => $case[0], 'categoryName' => 'Leaf ' . $case[0] ), 'leafCategoryTreeNode' => true, 'parentCategoryTreeNodeHref' => '/commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=1' . $case[0] ) ) ),
		'category_tree/100/get_category_suggestions'    => array( 200, array( 'categorySuggestions' => array( array( 'category' => array( 'categoryId' => $case[0], 'categoryName' => 'Leaf ' . $case[0] ), 'categoryTreeNodeAncestors' => array( array( 'categoryId' => '1' . $case[0], 'categoryName' => 'Middle' ), array( 'categoryId' => $case[1], 'categoryName' => $case[2] ) ) ) ) ) ),
	);
	check( sprintf( 'a category under %s named "%s": %s, by its ID', $case[1], $case[2], $case[3] ), TCGiant_Sync_Motors::resolve( $case[0] )['branch'], $case[3] );
}

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
	'category_tree/100/get_category_subtree'        => array( 200, array( 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => '34998', 'categoryName' => 'Automotive Tools & Supplies' ), 'leafCategoryTreeNode' => false, 'parentCategoryTreeNodeHref' => '/commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=6028' ) ) ),
);
$found = TCGiant_Sync_Motors::resolve( '34998' );
check( 'a child of Parts & Accessories (6028): Parts & Accessories from its node alone', array( $found['branch'], count( urls() ), TCGiant_Sync_Motors::record( '34998' ) ), array( 'parts', 2, array( 'b' => 'parts', 'p' => '6028' ) ) );

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
	'category_tree/100/get_category_subtree'        => array( 200, array( 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => '35999', 'categoryName' => 'Other' ), 'leafCategoryTreeNode' => true, 'parentCategoryTreeNodeHref' => '/commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=99' ) ) ),
	'category_tree/100/get_category_suggestions'    => array( 200, array( 'categorySuggestions' => array() ) ),
);
$found = TCGiant_Sync_Motors::resolve( '35999' );
check( 'one its node and suggestions do not place: in eBay Motors, its branch not known, and nothing said', array( $found['tree'], $found['branch'], $found['said'] ), array( '100', '', '' ) );
$GLOBALS['WIRE'] = array();
check( '  asked again within the day (a bulk push of it): nothing on the wire', array( TCGiant_Sync_Motors::resolve( '35999' ), urls() ), array( array( 'tree' => '100', 'branch' => '', 'said' => '', 'wait' => '' ), array() ) );
TCGiant_Sync_Motors::$clock = function () { return time() + DAY_IN_SECONDS + 5; };
TCGiant_Sync_Motors::resolve( '35999' );
TCGiant_Sync_Motors::$clock = null;
check( '  a day later: its node and suggestions asked again (not tree 0: it is known to be in eBay Motors)', urls(), array( 'commerce/taxonomy/v1/category_tree/100/get_category_subtree?category_id=35999', 'commerce/taxonomy/v1/category_tree/100/get_category_suggestions?q=Other' ) );
TCGiant_Sync_Motors::learn( array( '35999' => array( '', '99' ) ) );
TCGiant_Sync_Motors::learn( array( '35999' => array( 'parts', '' ) ) );
TCGiant_Sync_Motors::learn( array( '35999' => array( '', '' ) ) );
check( '  what is learnt of it later is kept: an answer that does not say does not unlearn it', TCGiant_Sync_Motors::record( '35999' ), array( 'b' => 'parts', 'p' => '99' ) );

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
	'category_tree/100/get_category_subtree'        => $not_in_tree,
);
$found = TCGiant_Sync_Motors::resolve( '99999999' );
check( 'in neither tree (an old ID eBay may still map): left as before 3.28.9, nothing learnt, nothing said', array( $found, get_option( TCGiant_Sync_Motors::OPTION, 'none' ) ), array( array( 'tree' => '', 'branch' => '', 'said' => '', 'wait' => '' ), 'none' ) );
$GLOBALS['WIRE'] = array();
TCGiant_Sync_Motors::resolve( '99999999' );
check( '  asked again within the day: tree 100 not asked again (the push\'s own tree-0 read still is, as before)', array( urls(), isset( $GLOBALS['TRANSIENTS'][ TCGiant_Sync_Motors::NOT_MOTORS_TRANSIENT . '99999999' ] ) ), array( array(), true ) );

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => array( 500, array( 'errors' => array( array( 'errorId' => 62000 ) ) ) ),
);
$found = TCGiant_Sync_Motors::resolve( '35560' );
check( 'tree 0 does not answer (eBay\'s 500): as before, ordinary, and tree 100 not asked', array( $found['tree'], count( urls() ) ), array( '', 3 ) );
check( '  held for the hour, as ordinary', array( $GLOBALS['TRANSIENTS'][ TCGiant_Sync_Motors::HOLD_TRANSIENT . '35560' ]['step'] ?? null ), array( 'ordinary' ) );
$GLOBALS['WIRE'] = array();
check( '  asked again within the hour: ordinary, nothing on the wire', array( TCGiant_Sync_Motors::resolve( '35560' ), urls() ), array( array( 'tree' => '', 'branch' => '', 'said' => '', 'wait' => '' ), array() ) );

fresh();
$GLOBALS['REPLIES'] = array(
	'category_tree/0/get_item_aspects_for_category' => $not_in_tree,
	'category_tree/100/get_category_subtree'        => array( 503, array( 'errors' => array( array( 'errorId' => 62000 ) ) ) ),
);
$found = TCGiant_Sync_Motors::resolve( '35560' );
check( 'not in tree 0, and tree 100 does not answer: a push waits, and it is said', array( $found['tree'], $found['branch'], '' !== $found['wait'], 0 === strpos( $found['said'], 'eBay did not say whether category 35560 is in eBay Motors Parts & Accessories (' ) ), array( '100', '', true, true ) );
check( '  held for the hour, nothing learnt', array( $GLOBALS['TRANSIENTS'][ TCGiant_Sync_Motors::HOLD_TRANSIENT . '35560' ]['step'] ?? null, get_option( TCGiant_Sync_Motors::OPTION, 'none' ) ), array( 'motors', 'none' ) );
$GLOBALS['WIRE'] = array();
$again = TCGiant_Sync_Motors::resolve( '35560' );
check( '  asked again within the hour: the same wait, nothing on the wire', array( $again['tree'], $again['wait'] === $found['wait'], urls() ), array( '100', true, array() ) );

echo "\nNODES AND SUGGESTIONS, AS EBAY GIVES THEM\n" . str_repeat( '=', 124 ) . "\n";

fresh();
$GLOBALS['REPLIES'] = array( 'get_category_subtree' => array( 200, array( 'categorySubtreeNode' => array( 'category' => array( 'categoryId' => '6028', 'categoryName' => 'Parts & Accessories' ), 'categoryTreeNodeLevel' => 1 ) ) ) );
check( 'a node at the top: no parent', $api->get_category_node( '6028', '100' ), array( 'id' => '6028', 'name' => 'Parts & Accessories', 'leaf' => false, 'level' => 1, 'parent' => '' ) );
$GLOBALS['REPLIES'] = array( 'get_category_suggestions' => array( 200, array( 'categorySuggestions' => array( array( 'category' => array( 'categoryId' => '33564', 'categoryName' => 'Calipers' ), 'categoryTreeNodeAncestors' => array( array( 'categoryId' => '33559', 'categoryName' => 'Brakes' ), array( 'categoryId' => '6028', 'categoryName' => 'Parts & Accessories' ) ) ) ) ) ) );
$GLOBALS['WIRE']    = array();
check( 'a suggestion: the path root first, the ancestors nearest first; "&" asked whole', array( $api->get_category_suggestions_in_tree( 'Brakes & Calipers', '100' ), urls() ), array( array( array( 'id' => '33564', 'name' => 'Parts & Accessories > Brakes > Calipers', 'ancestors' => array( '33559', '6028' ) ) ), array( 'commerce/taxonomy/v1/category_tree/100/get_category_suggestions?q=Brakes%20%26%20Calipers' ) ) );
check( '  its path under eBay Motors', TCGiant_Sync_Motors::motors_path( 'Parts & Accessories > Brakes > Calipers' ), 'eBay Motors > Parts & Accessories > Brakes > Calipers' );

echo "\n  $pass passed, $fail failed\n\n";
exit( $fail > 0 ? 1 : 0 );
