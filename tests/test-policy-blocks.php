<?php
/**
 * Harness: the policy blocks say what the listing's own policies say.
 *
 * {{{policy.returns}}} and {{{policy.handling}}} are rendered from the
 * Business Policies the same item puts into SellerProfiles - the shop's, with
 * the product's own shipping policy where it has one - from the data "Fetch
 * eBay Policies" keeps. Only facts that rarely change: returns accepted, the
 * period, who pays return shipping, and the handling time. Never a shipping
 * service or a cost. Missing data leaves the block out, with a warning.
 *
 * The policy answers are eBay's Account API v1 shapes (returnPolicies[],
 * fulfillmentPolicies[], TimeDuration {value, unit}), played.
 *
 * Loaded for real: the exporter's policy cache and its item XML, the policy
 * blocks, the templates and the sanitiser.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

$API = $GLOBALS['API'];
$API->policies = array(
	'return'      => array(
		array( 'returnPolicyId' => 'RP1', 'name' => '30 days, buyer pays', 'marketplaceId' => 'EBAY_US', 'returnsAccepted' => true, 'returnPeriod' => array( 'value' => 30, 'unit' => 'DAY' ), 'returnShippingCostPayer' => 'BUYER', 'refundMethod' => 'MONEY_BACK' ),
		array( 'returnPolicyId' => 'RP2', 'name' => 'Free returns', 'returnsAccepted' => true, 'returnPeriod' => array( 'value' => 60, 'unit' => 'DAY' ), 'returnShippingCostPayer' => 'SELLER' ),
		array( 'returnPolicyId' => 'RP3', 'name' => 'No returns', 'returnsAccepted' => false ),
		array( 'returnPolicyId' => 'RP4', 'name' => 'Broken', 'returnsAccepted' => true ),
		array( 'returnPolicyId' => 'RP5', 'name' => 'A month', 'returnsAccepted' => true, 'returnPeriod' => array( 'value' => 1, 'unit' => 'MONTH' ) ),
		array( 'returnPolicyId' => 'RP6', 'name' => 'A year', 'returnsAccepted' => true, 'returnPeriod' => array( 'value' => 1, 'unit' => 'YEAR' ) ),
		array( 'returnPolicyId' => 'RP9', 'name' => 'The listing\'s own', 'returnsAccepted' => true, 'returnPeriod' => array( 'value' => 14, 'unit' => 'DAY' ), 'returnShippingCostPayer' => 'BUYER' ),
	),
	'fulfillment' => array(
		array( 'fulfillmentPolicyId' => 'FP1', 'name' => 'Cards - First Class', 'handlingTime' => array( 'value' => 1, 'unit' => 'DAY' ), 'shippingOptions' => array( array( 'optionType' => 'DOMESTIC', 'shippingServices' => array( array( 'shippingServiceCode' => 'USPSFirstClass', 'shippingCost' => array( 'value' => '4.50', 'currency' => 'USD' ) ) ) ) ) ),
		array( 'fulfillmentPolicyId' => 'FP2', 'name' => 'Slabs - Priority', 'handlingTime' => array( 'value' => 3, 'unit' => 'DAY' ), 'shippingOptions' => array( array( 'shippingServices' => array( array( 'shippingServiceCode' => 'USPSPriority', 'shippingCost' => array( 'value' => '9.85', 'currency' => 'USD' ) ) ) ) ) ),
		array( 'fulfillmentPolicyId' => 'FP3', 'name' => 'Same day', 'handlingTime' => array( 'value' => 0, 'unit' => 'DAY' ) ),
		array( 'fulfillmentPolicyId' => 'FP4', 'name' => 'Local pickup', 'localPickup' => true ),
	),
	'payment'     => array( array( 'paymentPolicyId' => 'PP1', 'name' => 'eBay Payments' ) ),
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\"FETCH EBAY POLICIES\" KEEPS WHAT THE BLOCKS NEED\n" . str_repeat( '=', 124 ) . "\n";

TCGiant_Sync_Exporter::clear_policy_cache();
$returns     = TCGiant_Sync_Exporter::get_policies( 'return' );
$fulfillment = TCGiant_Sync_Exporter::get_policies( 'fulfillment' );
$payment     = TCGiant_Sync_Exporter::get_policies( 'payment' );
check( 'a return policy: returns accepted, the period, who pays return shipping', $returns[0], array( 'id' => 'RP1', 'name' => '30 days, buyer pays', 'returns_accepted' => true, 'return_period' => array( 'value' => 30, 'unit' => 'DAY' ), 'return_cost_payer' => 'BUYER' ) );
check( '  one with no returns: false, and no period', array( $returns[2]['returns_accepted'], $returns[2]['return_period'] ), array( false, null ) );
check( 'a shipping policy: its handling time - and no service or cost', $fulfillment[0], array( 'id' => 'FP1', 'name' => 'Cards - First Class', 'handling_time' => array( 'value' => 1, 'unit' => 'DAY' ) ) );
check( 'a payment policy: its ID and name, as before', $payment[0], array( 'id' => 'PP1', 'name' => 'eBay Payments' ) );
check( 'when they were fetched is kept', (int) get_option( 'tcgiant_export_policies_fetched' ) > 0, true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE BLOCKS SAY WHAT THE ITEM ATTACHES\n" . str_repeat( '=', 124 ) . "\n";

$html_of = function ( WC_Product $p, array $extra = array() ) {
	return described( $p, $extra )['html'];
};
$tpl = make_template( array( 'html' => '{{{description}}}{{#show.returns}}{{#policy.returns}}<h3>Returns</h3>{{{policy.returns}}}{{/policy.returns}}{{/show.returns}}{{#policy.handling}}<h3>Handling</h3>{{{policy.handling}}}{{/policy.handling}}' ) );
$card = new WC_Product( 71, array( 'name' => 'Card', 'description' => 'Near mint.' ) );
update_post_meta( 71, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';

$API->calls = array();
$html = $html_of( $card );
check( 'returns: from the return policy the item attaches', false !== strpos( $html, '<h3>Returns</h3><p class="tcg-policy-returns" style="margin:0 0 8px">Returns accepted within 30 days. The buyer pays return shipping.</p>' ), true );
check( 'handling: from the shipping policy the item attaches', false !== strpos( $html, 'Ships within 1 business day of cleared payment.' ), true );
check( 'rendering asked eBay nothing', $API->calls, array() );

$xml = item_xml( $card, push_settings( $card ) );
check( '  and those are the policies in the same item\'s SellerProfiles', array( false !== strpos( $xml, '<ReturnProfileID>RP1</ReturnProfileID>' ), false !== strpos( $xml, '<ShippingProfileID>FP1</ShippingProfileID>' ), false !== strpos( $xml, 'Returns accepted within 30 days' ) ), array( true, true, true ) );

update_post_meta( 71, '_ebay_export_fulfillment_policy', 'FP2' );
check( 'the product\'s own shipping policy: that policy\'s handling time', false !== strpos( $html_of( $card ), 'Ships within 3 business days of cleared payment.' ), true );
check( '  and the item sends that one', false !== strpos( item_xml( $card, push_settings( $card ) ), '<ShippingProfileID>FP2</ShippingProfileID>' ), true );
delete_post_meta( 71, '_ebay_export_fulfillment_policy' );

foreach ( array( 'USPSFirstClass', 'USPSPriority', '4.50', '9.85', 'First Class', 'Priority', 'eBay Payments' ) as $never ) {
	update_post_meta( 71, '_ebay_export_fulfillment_policy', 'FP2' );
	$a = $html_of( $card );
	delete_post_meta( 71, '_ebay_export_fulfillment_policy' );
	check( 'never a shipping service, a cost or a payment method: no "' . $never . '"', false === strpos( $a . $html_of( $card ), $never ), true );
}

$words = function ( $id ) use ( $html_of, $card ) {
	TCGiant_Sync_OAuth::$settings['export_return_policy'] = $id;
	$r = described( $card );
	TCGiant_Sync_OAuth::$settings['export_return_policy'] = 'RP1';
	preg_match( '#<p class="tcg-policy-returns"[^>]*>(.*?)</p>#', $r['html'], $m );
	return array( $m[1] ?? '', $r['warnings'] );
};
check( 'the seller pays return shipping', $words( 'RP2' )[0], 'Returns accepted within 60 days. We pay return shipping.' );
check( 'returns not accepted', $words( 'RP3' )[0], 'Returns are not accepted.' );
check( 'a period in months', $words( 'RP5' )[0], 'Returns accepted within 1 month.' );
check( 'a period in a unit the block cannot word (a year): left out, never taken for days', $words( 'RP6' )[0], '' );
TCGiant_Sync_OAuth::$settings['export_fulfillment_policy'] = 'FP3';
check( 'a handling time of 0: the same business day', false !== strpos( $html_of( $card ), 'Ships the same business day as cleared payment.' ), true );
TCGiant_Sync_OAuth::$settings['export_fulfillment_policy'] = 'FP1';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nMISSING DATA LEAVES THE BLOCK OUT, WITH A WARNING\n" . str_repeat( '=', 124 ) . "\n";

list( $text, $warns ) = $words( 'RP4' );
check( 'returns accepted but no period: left out', $text, '' );
check( '  and said', count( array_filter( $warns, function ( $w ) { return false !== strpos( $w, 'return period could not be read' ); } ) ), 1 );

list( $text, $warns ) = $words( 'RP-OTHER-SITE' );
check( 'a policy not among those fetched (another site\'s, say): left out', $text, '' );
check( '  and said, naming it', count( array_filter( $warns, function ( $w ) { return false !== strpos( $w, '(RP-OTHER-SITE) is not among the policies fetched' ); } ) ), 1 );
check( '  its heading goes with it, the section being empty', false === strpos( described( $card, array( 'return_policy_id' => 'RP-OTHER-SITE' ) )['html'], '<h3>Returns</h3>' ), true );

update_option( 'tcgiant_export_policies_return', array( array( 'id' => 'RP1', 'name' => '30 days, buyer pays' ) ) );
list( $text, $warns ) = $words( 'RP1' );
check( 'a cache fetched before 3.27.8 kept these fields: left out', $text, '' );
check( '  and the warning says to fetch again', count( array_filter( $warns, function ( $w ) { return false !== strpos( $w, 'fetched before this version' ); } ) ), 1 );
TCGiant_Sync_Exporter::clear_policy_cache();
TCGiant_Sync_Exporter::get_policies( 'return' );
TCGiant_Sync_Exporter::get_policies( 'fulfillment' );

TCGiant_Sync_OAuth::$settings['export_fulfillment_policy'] = 'FP4';
$r = described( $card );
check( 'a shipping policy with no handling time (local pickup): left out, and said', array( false === strpos( $r['html'], 'tcg-policy-handling' ), count( array_filter( $r['warnings'], function ( $w ) { return false !== strpos( $w, 'no handling time' ); } ) ) ), array( true, 1 ) );
TCGiant_Sync_OAuth::$settings['export_fulfillment_policy'] = 'FP1';

$r = described( $card, array( 'return_policy_id' => '' ) );
check( 'a send with no return policy in SellerProfiles: the block is left out', false === strpos( $r['html'], 'tcg-policy-returns' ), true );
check( '  and the warning says no return policy is sent', count( array_filter( $r['warnings'], function ( $w ) { return false !== strpos( $w, 'No return policy is sent with this listing' ); } ) ), 1 );

// The Blocks screen shows the shop's own policies: worded for the shop,
// with no listing in sight (browser review, L8).
$shop_blocks = TCGiant_Sync_Template_Policies::blocks( array( 'return_policy_id' => '', 'fulfillment_policy_id' => 'FP-NOT-FETCHED' ), true );
check( 'on the Blocks screen: "your shop sends", never "this listing"', array( false !== strpos( $shop_blocks['warnings']['returns'], 'Your shop sends no return policy' ), false !== strpos( $shop_blocks['warnings']['handling'], 'policy your shop sends (FP-NOT-FETCHED)' ), false === strpos( implode( ' ', $shop_blocks['warnings'] ), 'this listing' ) ), array( true, true, true ) );

$plain = make_template( array( 'html' => '{{{description}}}' ) );
update_post_meta( 71, TCGiant_Sync_Templates::OVERRIDE_META, (string) $plain );
check( 'a template that uses no policy block warns about none', count( array_filter( described( $card, array( 'return_policy_id' => 'RP4' ) )['warnings'], function ( $w ) { return false !== stripos( $w, 'policy' ); } ) ), 0 );
update_post_meta( 71, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAN IMPORTED LISTING, AND ANOTHER LANGUAGE\n" . str_repeat( '=', 124 ) . "\n";

// The import records the listing's own return policy (RP9, 14 days). A
// 3.27.8 push sends the shop's (RP1), so the block says what is sent; 3.28.1's
// item 13, which leaves SellerProfiles alone, will render the listing's own.
update_post_meta( 71, '_ebay_return_policy_id', 'RP9' );
update_post_meta( 71, '_ebay_item_id', '110000000071' );
$html = $html_of( $card );
check( 'an imported listing with its own return policy: the block says what this push sends', array( false !== strpos( $html, 'within 30 days' ), false === strpos( $html, 'within 14 days' ) ), array( true, true ) );
check( '  which is the policy in its SellerProfiles', false !== strpos( item_xml( $card, push_settings( $card ) ), '<ReturnProfileID>RP1</ReturnProfileID>' ), true );
delete_post_meta( 71, '_ebay_item_id' );

TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_DE';
$de = make_template( array( 'lang' => 'de', 'html' => '{{{description}}}{{{policy.returns}}}' ) );
update_post_meta( 71, TCGiant_Sync_Templates::OVERRIDE_META, (string) $de );
$r = described( $card );
check( 'a German template on eBay Germany: the policy wording is English only in 3.27.8, and it says so', count( array_filter( $r['warnings'], function ( $w ) { return false !== strpos( $w, 'English only' ) && false !== strpos( $w, 'DE' ); } ) ), 1 );
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_GB';
check( '  on eBay UK, English: no such warning', count( array_filter( described( $card )['warnings'], function ( $w ) { return false !== strpos( $w, 'English only' ); } ) ), 0 );
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_US';

done();
