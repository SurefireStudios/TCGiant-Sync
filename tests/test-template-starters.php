<?php
/**
 * Harness: the five starter templates.
 *
 * Installed once, as drafts, with nothing assigned. Each keeps its layout in
 * inline styles, so with its stylesheet gone - if eBay drops <style> - it
 * still reads as one column; has no JavaScript and no fixed width over 700 px;
 * reads at 375 px; is about 20 KB or less; and leaves out a part with no data
 * rather than printing filler.
 *
 * Loaded for real: the starters, the templates, the renderer, the sanitiser,
 * WordPress's kses, and the exporter that gathers what a description says.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

function is_admin() { return $GLOBALS['W']['is_admin'] ?? true; }

$starters = TCGiant_Sync_Template_Starters::all();

// ─────────────────────────────────────────────────────────────────────────────
echo "\nINSTALLED ONCE, AS DRAFTS, WITH NOTHING ASSIGNED\n" . str_repeat( '=', 124 ) . "\n";

check( 'the five: Clean, Trading cards, Coins, Sealed or general, Dark', array_column( $starters, 'name' ), array( 'Clean', 'Trading cards', 'Coins', 'Sealed or general', 'Dark' ) );

$GLOBALS['W']['is_admin'] = false;
TCGiant_Sync_Template_Starters::maybe_install();
check( 'not on a request outside the admin', TCGiant_Sync_Templates::all(), array() );
$GLOBALS['W']['is_admin'] = true;

TCGiant_Sync_Template_Starters::maybe_install();
$all = TCGiant_Sync_Templates::all();
check( 'installed: five templates', count( $all ), 5 );
check( '  every one a draft', array_unique( array_column( $all, 'status' ) ), array( 'draft' ) );
check( '  nothing assigned: the default is None, no rules, no product set', array( TCGiant_Sync_Templates::settings()['default_template_id'], TCGiant_Sync_Templates::settings()['category_map'] ), array( 0, array() ) );
TCGiant_Sync_Template_Starters::maybe_install();
check( 'a second run installs nothing more', count( TCGiant_Sync_Templates::all() ), 5 );

$installed = get_option( TCGiant_Sync_Templates::STARTERS_OPTION )['installed'];
wp_delete_post( $installed['dark'], true );
update_option( TCGiant_Sync_Templates::STARTERS_OPTION, array( 'version' => 0, 'installed' => $installed ) );
TCGiant_Sync_Template_Starters::maybe_install();
check( 'one the shop deleted is not brought back, even when the list is read again', count( TCGiant_Sync_Templates::all() ), 4 );
update_option( TCGiant_Sync_Templates::STARTERS_OPTION, array( 'version' => TCGiant_Sync_Template_Starters::VERSION, 'installed' => array() ) );
TCGiant_Sync_Template_Starters::maybe_install();
check( 'once this version\'s starters are recorded, the list is not read again: nothing added', count( TCGiant_Sync_Templates::all() ), 4 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT EVERY STARTER IS\n" . str_repeat( '=', 124 ) . "\n";

// A product with everything a starter can show, and one with nothing but a name.
$GLOBALS['W']['images'] = array( 801 => 'https://shop.example/logo.png', 802 => 'https://shop.example/front.jpg', 803 => 'https://shop.example/back.jpg' );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'shop_name' => 'The Cardboard Shop', 'logo_id' => 801, 'ebay_store_url' => 'https://www.ebay.com/str/thecardboardshop' ) );
update_option( 'tcgiant_export_policies_return', array( array( 'id' => 'RP1', 'name' => '30', 'returns_accepted' => true, 'return_period' => array( 'value' => 30, 'unit' => 'DAY' ), 'return_cost_payer' => 'BUYER' ) ) );
update_option( 'tcgiant_export_policies_fulfillment', array( array( 'id' => 'FP1', 'name' => 'FC', 'handling_time' => array( 'value' => 1, 'unit' => 'DAY' ) ) ) );

$full = new WC_Product( 811, array( 'name' => 'Charizard 4/102 Holo PSA 9', 'description' => "<p>Shadowless holo, centred.</p>\n<p>Ships in a graded-card sleeve.</p>", 'short_description' => 'A PSA 9 shadowless Charizard.', 'image_id' => 802, 'gallery' => array( 803 ),
	'attributes' => array( new WC_Product_Attribute( 'Set', array( 'Base Set' ) ), new WC_Product_Attribute( 'Card Number', array( '4/102' ) ) ) ) );
foreach ( array( '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '9', '_ebay_export_cert_number' => '95422421' ) as $k => $v ) {
	update_post_meta( 811, $k, $v );
}
$bare = new WC_Product( 812, array( 'name' => 'Sealed booster box' ) );
$bare_settings = array( 'return_policy_id' => '', 'fulfillment_policy_id' => '' );

$render = function ( WC_Product $p, $slug, array $extra = array(), array $design = array() ) {
	$draft = TCGiant_Sync_Template_Starters::get( $slug );
	$draft['design'] = array_merge( $draft['design'], $design );
	return described( $p, array_merge( array( 'template_draft' => $draft ), $extra ) );
};

foreach ( $starters as $slug => $starter ) {
	$bytes = strlen( $starter['html'] . $starter['css'] );
	check( $slug . ': about 20 KB or less', $bytes <= 20480, true );
	check( '  no JavaScript', false === stripos( $starter['html'] . $starter['css'], '<script' ) && false === stripos( $starter['html'], 'javascript:' ) && 0 === preg_match( '/\son[a-z]+\s*=/i', $starter['html'] ), true );
	check( '  no display:, which WordPress\'s style filter would remove', false === strpos( $starter['html'], 'display:' ), true );
	preg_match_all( '/(?<![\w\-])width\s*:\s*(\d+)px/i', $starter['html'], $w );
	check( '  no fixed width over 700 px', array_values( array_filter( array_map( 'intval', $w[1] ), function ( $px ) { return $px > 700; } ) ), array() );
	check( '  no "replace this text" filler', 0 === preg_match( '/replace this|lorem ipsum|your text here|sample text/i', $starter['html'] ), true );

	$out = $render( $full, $slug );
	check( '  renders with no unknown placeholder and no warning of its own', array_values( array_filter( $out['warnings'], function ( $x ) { return false !== strpos( $x, 'placeholder' ) || false !== strpos( $x, 'never closed' ) || false !== strpos( $x, 'not open' ); } ) ), array() );
	check( '  removes nothing of its own', $out['removed'], array() );
	check( '  well under eBay\'s ceiling', $out['chars'] < 25000, true );
	check( '  holds the title, the description and the Store link', array( false !== strpos( $out['html'], 'Charizard 4/102 Holo PSA 9</h1>' ), false !== strpos( $out['html'], 'Shadowless holo, centred.' ), false !== strpos( $out['html'], 'href="https://www.ebay.com/str/thecardboardshop"' ) ), array( true, true, true ) );
	check( '  the specifics and both policy blocks', array( false !== strpos( $out['html'], 'Item specifics</h2>' ), false !== strpos( $out['html'], 'Returns accepted within 30 days' ), false !== strpos( $out['html'], 'Ships within 1 business day' ) ), array( true, true, true ) );
	check( '  the shop\'s logo in the header', false !== strpos( $out['html'], 'src="https://shop.example/logo.png"' ), true );

	// With its stylesheet gone, as if eBay dropped <style>: one column, read in order.
	$plain = preg_replace( '#<style>.*?</style>#s', '', $out['html'] );
	$order = array( strpos( $plain, '</h1>' ), strpos( $plain, 'Shadowless holo' ), strpos( $plain, 'Item specifics' ), strpos( $plain, 'Returns accepted' ) );
	check( '  without its stylesheet: the page keeps its own width and centring', false !== strpos( $plain, '<div class="tcg-page" style="max-width:700px;margin:0 auto;' ), true );
	check( '  and reads top to bottom in one column, no floats', array( 'general' === $slug ? $order[0] < $order[2] && $order[2] < $order[1] : $order[0] < $order[1] && $order[1] < $order[2], $order[2] < $order[3], false === strpos( $plain, 'float:' ) ), array( true, true, true ) );
	preg_match_all( '/(?<![\w\-])width\s*:\s*(\d+)px/i', $plain, $w );
	check( '  nothing wider than a 375 px phone', array_values( array_filter( array_map( 'intval', $w[1] ), function ( $px ) { return $px > 375; } ) ), array() );

	$out = $render( $bare, $slug, $bare_settings );
	check( '  a product with nothing but a name: no empty part, no heading left alone', array( false === strpos( $out['html'], 'Item specifics' ), false === strpos( $out['html'], 'Returns' ), false === strpos( $out['html'], 'Handling' ), false === strpos( $out['html'], 'tcg-panel' ), false === strpos( $out['html'], 'tcg-grading' ), false === strpos( $out['html'], 'More photos' ) ), array( true, true, true, true, true, true ) );
	check( '  and its name as the description, as the plain path has it', false !== strpos( $out['html'], 'Sealed booster box</div>' ), true );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEACH ONE'S OWN\n" . str_repeat( '=', 124 ) . "\n";

$cards = $render( $full, 'cards' )['html'];
check( 'Trading cards: a grading panel - grade, grader and cert', array( false !== strpos( $cards, 'tcg-panel' ), false !== strpos( $cards, '>9</div>' ), false !== strpos( $cards, 'Professional Sports Authenticator (PSA)' ), false !== strpos( $cards, 'Cert. no. 95422421' ) ), array( true, true, true, true ) );
check( '  and the condition name', false !== strpos( $cards, 'Condition: <strong' ) && false !== strpos( $cards, '>Graded</strong>' ), true );
update_post_meta( 811, '_ebay_export_condition_type', 'ungraded' );
update_post_meta( 811, '_ebay_export_ungraded_condition', '400010' );
$raw = $render( $full, 'cards' )['html'];
check( '  an ungraded card: no panel, its condition named', array( false === strpos( $raw, 'tcg-panel' ), false !== strpos( $raw, 'Ungraded - Near Mint or Better' ) ), array( true, true ) );
update_post_meta( 811, '_ebay_export_condition_type', 'graded' );

$GLOBALS['W']['meta'][811]['_ebay_export_category_id']   = '253';
$GLOBALS['W']['meta'][811]['_ebay_export_grader_id']     = '14';
$GLOBALS['W']['meta'][811]['_ebay_export_grade_value']   = 'MS/PR 65';
$GLOBALS['W']['meta'][811]['_ebay_export_coin_year']     = '1921';
$coin = $render( $full, 'coins' )['html'];
check( 'Coins: a grade and cert panel', array( false !== strpos( $coin, 'MS/PR 65' ), false !== strpos( $coin, 'Graded by <strong>PCGS</strong>' ), false !== strpos( $coin, 'Certification no. 95422421' ) ), array( true, true, true ) );
check( '  the year through the item specifics', 1 === preg_match( '#<th[^>]*>Year</th><td[^>]*>1921</td>#', $coin ), true );
foreach ( array( '_ebay_export_category_id', '_ebay_export_coin_year' ) as $k ) {
	unset( $GLOBALS['W']['meta'][811][ $k ] );
}

$general = $render( $full, 'general' )['html'];
check( 'Sealed or general: the short description leads, then the specifics, then the description', strpos( $general, 'A PSA 9 shadowless' ) < strpos( $general, 'Item specifics' ) && strpos( $general, 'Item specifics' ) < strpos( $general, 'Shadowless holo, centred' ), true );
check( '  and no grading panel', false === strpos( $general, 'tcg-panel' ), true );

$dark = $render( $full, 'dark' )['html'];
check( 'Dark: a dark page, with light text worked out from it', false !== strpos( $dark, 'background:#141414;color:#f2f2f2' ), true );
check( '  its colours changed on the design form', false !== strpos( $render( $full, 'dark', array(), array( 'background' => '#0b1f3a', 'accent' => '#ffcc00' ) )['html'], 'background:#0b1f3a;color:#f2f2f2' ), true );

$with_gallery = $render( $full, 'clean', array(), array( 'show' => array( 'gallery' => true ) ) )['html'];
check( 'the gallery, turned on: a static grid of the site\'s own images', array( false !== strpos( $with_gallery, 'More photos' ), substr_count( $with_gallery, 'float:left' ) ), array( true, 2 ) );
check( '  off by default', false === strpos( $render( $full, 'clean' )['html'], 'More photos' ), true );
check( 'a part turned off on the form goes', false === strpos( $render( $full, 'clean', array(), array( 'show' => array( 'returns' => false ) ) )['html'], 'Returns accepted' ), true );

// A shop with no logo, name or banner gets no empty accent bar (converter
// review L8a; browser review L2).
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array() );
check( 'no logo, no shop name, no banner: no header bar at all', false === strpos( $render( $full, 'clean' )['html'], 'tcg-header' ), true );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'shop_name' => 'The Cardboard Shop' ) );
$named = $render( $full, 'clean' )['html'];
check( '  a shop name alone: the bar, with the name in it', array( 1 === substr_count( $named, 'tcg-header' ), false !== strpos( $named, 'The Cardboard Shop</div></div>' ) ), array( true, true ) );

// The fixed headings go through the plugin's translations (L8d).
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-starters.php' );
$untranslated = array();
foreach ( array( 'Item specifics', 'Options', 'Returns', 'Handling', 'Condition:', 'GRADE', 'Cert. no.', 'Cert.', 'Graded by', 'Certification no.', 'More photos', 'See more in our eBay Store' ) as $heading ) {
	if ( false === strpos( $src, "__( '" . $heading . "', 'tcgiant-sync' )" ) ) {
		$untranslated[] = $heading;
	}
}
check( 'every fixed heading is written through __() with the plugin\'s text domain', $untranslated, array() );

done();
