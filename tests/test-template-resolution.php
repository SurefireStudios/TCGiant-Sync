<?php
/**
 * Harness: which template a product's listing uses.
 *
 * First match wins: the product's own choice on its eBay tab; the WooCommerce
 * category map, the most specific category first and then priority; the shop
 * default; None. "None" chosen on a product beats every rule, and a template
 * that has been deleted, or put back to draft, sends None with a line in the
 * log rather than a listing with no description at all.
 *
 * Loaded for real: the templates' resolution and settings sanitiser, the
 * exporter's get_export_settings() and its description, and the language
 * lint.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// Pokemon > Base Set > Holo, Pokemon > Jungle, and Coins.
$GLOBALS['W']['terms'] = array(
	10 => array( 'parent' => 0 ),
	11 => array( 'parent' => 10 ),
	12 => array( 'parent' => 11 ),
	13 => array( 'parent' => 10 ),
	20 => array( 'parent' => 0 ),
);

$a = make_template( array( 'name' => 'Default' ) );
$b = make_template( array( 'name' => 'Pokemon' ) );
$c = make_template( array( 'name' => 'Base Set' ) );
$e = make_template( array( 'name' => 'Jungle' ) );
$f = make_template( array( 'name' => 'Own choice' ) );

$settings = TCGiant_Sync_Templates::sanitize_settings( array(
	'default_template_id' => $a,
	'category_map'        => array(
		array( 'term_id' => 10, 'template_id' => $b, 'priority' => 1 ),
		array( 'term_id' => 11, 'template_id' => $c, 'priority' => 50 ),
		array( 'term_id' => 13, 'template_id' => $e, 'priority' => 5 ),
	),
) );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );

$used = function ( WC_Product $p ) {
	return TCGiant_Sync_Templates::resolve( $p );
};

// ─────────────────────────────────────────────────────────────────────────────
echo "\nFIRST MATCH WINS\n" . str_repeat( '=', 124 ) . "\n";

$holo = new WC_Product( 51, array( 'name' => 'Holo', 'categories' => array( 12 ) ) );
check( 'the most specific category wins: Holo is in Base Set, which beats Pokemon', $used( $holo ), array( 'template_id' => $c, 'why' => 'category' ) );
check( '  however low its priority (50 against 1)', $settings['category_map'][1]['priority'], 50 );

$two = new WC_Product( 52, array( 'name' => 'Both', 'categories' => array( 11, 13 ) ) );
check( 'two categories as deep: the lower priority number wins (Jungle 5 before Base Set 50)', $used( $two )['template_id'], $e );

$top = new WC_Product( 53, array( 'name' => 'Top', 'categories' => array( 10 ) ) );
check( 'a product in the parent category itself: its rule', $used( $top )['template_id'], $b );

$coin = new WC_Product( 54, array( 'name' => 'Coin', 'categories' => array( 20 ) ) );
check( 'no rule for its category: the default', $used( $coin ), array( 'template_id' => $a, 'why' => 'default' ) );

update_post_meta( 51, TCGiant_Sync_Templates::OVERRIDE_META, (string) $f );
check( 'the product\'s own choice beats its category', $used( $holo ), array( 'template_id' => $f, 'why' => 'override' ) );
update_post_meta( 51, TCGiant_Sync_Templates::OVERRIDE_META, 'none' );
check( '"None" on the product beats a category rule', $used( $holo ), array( 'template_id' => 0, 'why' => 'override_none' ) );
update_post_meta( 51, TCGiant_Sync_Templates::OVERRIDE_META, '' );
check( '"Follow the rules" follows them', $used( $holo )['template_id'], $c );

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array_merge( $settings, array( 'default_template_id' => 0 ) ) );
check( 'no rule and no default: None', $used( $coin ), array( 'template_id' => 0, 'why' => 'none' ) );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );

check( 'the push resolves it into its own settings', array( push_settings( $holo )['template_id'], push_settings( $holo )['template_why'] ), array( $c, 'category' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA TEMPLATE THAT HAS GONE SENDS NONE, AND SAYS SO\n" . str_repeat( '=', 124 ) . "\n";

$gone = make_template( array( 'name' => 'Gone' ) );
update_post_meta( 54, TCGiant_Sync_Templates::OVERRIDE_META, (string) $gone );
wp_delete_post( $gone, true );
$GLOBALS['W']['log'] = array();
check( 'a product set to a deleted template: None', $used( $coin ), array( 'template_id' => 0, 'why' => 'missing' ) );
$used( $coin );
$said = array_values( array_filter( $GLOBALS['W']['log'], function ( $l ) use ( $gone ) { return false !== strpos( $l[1], '#' . $gone . ' ' ); } ) );
check( '  with a line in the log, once a request', count( $said ), 1 );
check( '  saying which rule chose it and for which product', false !== strpos( $said[0][1] ?? '', 'product\'s own' ) && false !== strpos( $said[0][1] ?? '', 'WC #54' ), true );
check( '  and its description is the plain one', described( $coin )['none'], true );

$GLOBALS['W']['posts'][ $e ]->post_status = 'draft';
check( 'a category rule whose template went back to draft: None, not the next rule', $used( $two ), array( 'template_id' => 0, 'why' => 'missing' ) );
$GLOBALS['W']['posts'][ $e ]->post_status = 'publish';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SETTINGS FORM KEEPS ONLY WHAT CAN WORK\n" . str_repeat( '=', 124 ) . "\n";

$draft  = make_template( array( 'name' => 'Draft', 'status' => 'draft' ) );
$clean  = TCGiant_Sync_Templates::sanitize_settings( array(
	'default_template_id' => $draft,
	'category_map'        => array(
		array( 'term_id' => 999, 'template_id' => $b, 'priority' => 1 ),
		array( 'term_id' => 20, 'template_id' => $draft, 'priority' => 1 ),
		array( 'term_id' => 20, 'template_id' => 88888, 'priority' => 1 ),
		array( 'term_id' => 12, 'template_id' => $b, 'priority' => 500 ),
		'garbage',
	),
	'meta_allowlist'      => "card_year, _cost\nkey!\ncost<price>\ncard_year",
	'verify_daily_cap'    => '9999',
	'shop_name'           => '<b>The Cardboard Shop</b>',
	'ebay_store_url'      => 'http://www.ebay.com/str/thecardboardshop',
	'resend_daily_cap'    => 2000,
) );
check( 'a draft cannot be the default', $clean['default_template_id'], 0 );
check( 'rules for a category that does not exist, a draft or a missing template are dropped', array_column( $clean['category_map'], 'term_id' ), array( 12 ) );
check( '  and a priority is kept between 1 and 99', $clean['category_map'][0]['priority'], 99 );
check( 'the meta allowlist: valid keys, once each', $clean['meta_allowlist'], array( 'card_year', '_cost' ) );
check( 'Check with eBay\'s daily cap is bounded', $clean['verify_daily_cap'], 200 );
check( 'the shop name is text', $clean['shop_name'], 'The Cardboard Shop' );
check( 'the eBay Store address must be eBay\'s, and is made https', $clean['ebay_store_url'], 'https://www.ebay.com/str/thecardboardshop' );
check( '  an address that is not eBay\'s is refused', TCGiant_Sync_Templates::sanitize_settings( array( 'ebay_store_url' => 'https://evil.example/str/x' ) )['ebay_store_url'], '' );
check( 'nothing else is kept - not the 3.28.1 re-send cap', array_keys( $clean ), array_keys( TCGiant_Sync_Templates::default_settings() ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE TEMPLATE'S LANGUAGE AGAINST THE SITE'S\n" . str_repeat( '=', 124 ) . "\n";

$de_tpl = make_template( array( 'name' => 'Deutsch', 'lang' => 'de', 'html' => '<div>{{{description}}}</div>' ) );
$p      = new WC_Product( 55, array( 'name' => 'Karte', 'description' => 'Text.' ) );
update_post_meta( 55, TCGiant_Sync_Templates::OVERRIDE_META, (string) $de_tpl );
$warns = implode( ' | ', described( $p )['warnings'] );
check( 'a German template on a US shop warns', false !== strpos( $warns, 'written in DE, but your eBay site\'s language is EN' ), true );

TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_DE';
check( '  and on a German shop it does not', false === strpos( implode( ' | ', described( $p )['warnings'] ), 'written in' ), true );
$en_tpl = make_template( array( 'name' => 'English', 'lang' => 'en', 'html' => '<div>{{{description}}}</div>' ) );
update_post_meta( 55, TCGiant_Sync_Templates::OVERRIDE_META, (string) $en_tpl );
check( 'an English template on a German shop warns', false !== strpos( implode( ' | ', described( $p )['warnings'] ), 'written in EN, but your eBay site\'s language is DE' ), true );
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_US';

done();
