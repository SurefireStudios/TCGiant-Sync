<?php
/**
 * Harness: an import never writes a marked-up eBay price into the website
 * price (3.29.0).
 *
 * Until 3.29.0 an import with "Overwrite Price" on wrote eBay's price into
 * the website price, and a push sent the website price back. A shop could
 * have no other price on eBay. Now a product, or a variation, can have an
 * eBay price of its own, and the shop a rule (a percentage, an amount, a
 * rounding, tax); the import then decides by TCGiant_Sync_Ebay_Price:
 *
 *  - a product with its own eBay price has eBay's changes put in that field,
 *    and its website price left alone;
 *  - under the rule, a new product's website price is the one the rule turns
 *    into eBay's; an existing one's changes only when eBay's price has
 *    changed since it was last sent or read and the rule does not already
 *    give it, and then to the website price the rule turns into eBay's -
 *    the one nearest the exact reverse that gives it to the cent, rounding
 *    being lossy, or, where none does, the exact reverse, said in the log;
 *  - with neither, as before.
 *
 * And the postage "Add eBay Shipping Cost to Product Price" adds to the
 * website price is recorded, for a push to take off again.
 *
 * Loaded for real: TCGiant_Sync_Mapper (map_ebay_to_woo(), save_as_product(),
 * save_variations(), settings_fingerprint()) and TCGiant_Sync_Ebay_Price,
 * in support/ebay-price-import-world.php; eBay's answers are its XML turned
 * into arrays as the API class turns them.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-96s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 220 ) );
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}

require __DIR__ . '/support/ebay-price-import-world.php';

/** The price lines of the newest sync log entry. */
function price_lines( $id ) {
	return array_values( array_filter( decided( $id ), function ( $line ) { return false !== stripos( $line, 'price' ); } ) );
}
/** The shop's settings, with these. */
function shop( array $settings = array() ) {
	$GLOBALS['SETTINGS'] = array_merge( array( 'import_auctions' => '1' ), $settings );
}
/** The log since a point. */
function logged_since( $from ) {
	return array_slice( $GLOBALS['LOG'], $from );
}

$FLAT = '<ShippingDetails><ShippingType>Flat</ShippingType><ShippingServiceOptions><ShippingService>USPSFirstClass</ShippingService><ShippingServiceCost currencyID="USD">4.5</ShippingServiceCost><ShippingServicePriority>1</ShippingServicePriority></ShippingServiceOptions></ShippingDetails>';
$FREE = '<ShippingDetails><ShippingType>Flat</ShippingType><ShippingServiceOptions><ShippingService>USPSGround</ShippingService><ShippingServiceCost currencyID="USD">0.0</ShippingServiceCost><ShippingServicePriority>1</ShippingServicePriority><FreeShipping>true</FreeShipping></ShippingServiceOptions></ShippingDetails>';
$PLUS10 = array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT THE MAPPER HANDS ON: EBAY'S OWN PRICE, AND THE POSTAGE ADDED\n" . str_repeat( '=', 124 ) . "\n";

shop();
$data = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( listing( '2000', '24.5', $FLAT ) );
check( 'postage not added: the website\'s price is eBay\'s, and no postage', array( $data['regular_price'], $data['ebay_price'], $data['baked_shipping'] ), array( '24.5', '24.5', 0.0 ) );
shop( array( 'bake_shipping_into_price' => '1' ) );
$data = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( listing( '2000', '24.5', $FLAT ) );
check( 'postage added: 24.50 + 4.50 for the website, 24.5 eBay\'s own, 4.5 added', array( $data['regular_price'], $data['ebay_price'], $data['baked_shipping'] ), array( '29.00', '24.5', 4.5 ) );
$data = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( listing( '2000', '32.0', $FLAT . '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">40.0</OriginalRetailPrice></DiscountPriceInfo>' ) );
check( 'a markdown: the original is eBay\'s price, the markdown its sale, each without postage', array( $data['regular_price'], $data['sale_price'], $data['ebay_price'], $data['ebay_sale_price'] ), array( '44.50', '36.50', '40.0', '32.0' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNO EBAY PRICE OF ITS OWN, NO RULE: AS BEFORE 3.29.0\n" . str_repeat( '=', 124 ) . "\n";

shop();
linked_product( 100, '2001', array( '_regular_price' => '20.00' ) );
import( listing( '2001', '24.5' ) );
check( 'Overwrite Price on: eBay\'s price is the website\'s, as before', array( prices( 100 ), price_lines( 100 ) ), array( array( '24.5', '' ), array( 'Price updated (eBay won)' ) ) );
check( '  and eBay\'s price is noted, for the rule to tell a change by later', meta( 100, '_ebay_price_seen' ), '24.50' );
shop( array( 'overwrite_price' => '0' ) );
linked_product( 101, '2002', array( '_regular_price' => '20.00' ) );
import( listing( '2002', '24.5' ) );
check( 'Overwrite Price off: the website price kept, as before', array( prices( 101 )[0], price_lines( 101 ) ), array( '20.00', array( 'Price skipped (WooCommerce won)' ) ) );
check( '  eBay\'s price noted all the same', meta( 101, '_ebay_price_seen' ), '24.50' );
shop();
$new = import( listing( '2003', '24.5' ) );
check( 'a new product: eBay\'s price', array( prices( $new ), meta( $new, '_ebay_price_seen' ) ), array( array( '24.5', '' ), '24.50' ) );
shop( array( 'bake_shipping_into_price' => '1' ) );
linked_product( 102, '2004', array( '_regular_price' => '20.00' ) );
import( listing( '2004', '24.5', $FLAT ) );
check( 'postage added on import: the website price 29.00, and the 4.50 in it recorded for a push to take off', array( prices( 102 )[0], meta( 102, '_ebay_baked_shipping' ), meta( 102, '_ebay_price_seen' ) ), array( '29.00', '4.50', '24.50' ) );
import( listing( '2004', '24.5', $FREE ) );
check( '  free postage next time: 24.5, and no postage recorded', array( prices( 102 )[0], meta( 102, '_ebay_baked_shipping' ) ), array( '24.5', null ) );
shop( array( 'bake_shipping_into_price' => '1', 'overwrite_price' => '0' ) );
update_post_meta( 102, '_ebay_baked_shipping', '4.50' );
import( listing( '2004', '30.0', $FREE ) );
check( '  a website price the import does not write keeps the postage recorded for it', meta( 102, '_ebay_baked_shipping' ), '4.50' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT WITH ITS OWN EBAY PRICE: EBAY'S CHANGES GO IN THAT FIELD\n" . str_repeat( '=', 124 ) . "\n";

shop( $PLUS10 );
linked_product( 110, '2010', array( '_regular_price' => '20.00', '_ebay_export_price' => '30.00', '_ebay_price_seen' => '30.00' ) );
import( listing( '2010', '30.0' ) );
check( 'eBay at its own price: nothing changes - not the website price, not the field', array( prices( 110 )[0], meta( 110, '_ebay_export_price' ), price_lines( 110 ) ), array( '20.00', '30.00', array( 'Price kept: eBay has this product\'s own eBay price' ) ) );
import( listing( '2010', '32.0' ) );
check( 'eBay\'s price changed on eBay: the field takes it, the website price is left alone', array( prices( 110 ), meta( 110, '_ebay_export_price' ), price_lines( 110 ) ), array( array( '20.00', null ), '32.00', array( 'eBay price updated to 32.00 (eBay won); the website price is left as it is' ) ) );
check( '  and the change noted', meta( 110, '_ebay_price_seen' ), '32.00' );
update_post_meta( 110, '_ebay_export_price', '29.00' );
import( listing( '2010', '32.0' ) );
check( 'the field changed here since, its push still to come: eBay\'s unchanged price does not undo it', array( meta( 110, '_ebay_export_price' ), prices( 110 )[0] ), array( '29.00', '20.00' ) );
linked_product( 114, '2014', array( '_regular_price' => '20.00', '_ebay_export_price' => '30.00', '_ebay_price_seen' => '28.00' ) );
import( listing( '2014', '30.0' ) );
check( 'eBay changed, to the field\'s own price (its push landing): nothing to write', array( meta( 114, '_ebay_export_price' ), price_lines( 114 ), meta( 114, '_ebay_price_seen' ) ), array( '30.00', array( 'Price kept: eBay has this product\'s own eBay price' ), '30.00' ) );
linked_product( 111, '2011', array( '_regular_price' => '20.00', '_ebay_export_price' => '30.00' ) );
import( listing( '2011', '35.0' ) );
check( 'eBay\'s price never noted before: not taken as a change - the field kept', array( meta( 111, '_ebay_export_price' ), prices( 111 )[0], meta( 111, '_ebay_price_seen' ) ), array( '30.00', '20.00', '35.00' ) );
shop( $PLUS10 + array( 'overwrite_price' => '0' ) );
linked_product( 112, '2012', array( '_regular_price' => '20.00', '_ebay_export_price' => '30.00', '_ebay_price_seen' => '30.00' ) );
import( listing( '2012', '32.0' ) );
check( 'Overwrite Price off: neither the field nor the website price', array( meta( 112, '_ebay_export_price' ), prices( 112 )[0], price_lines( 112 ) ), array( '30.00', '20.00', array( 'Price skipped (WooCommerce won)' ) ) );
shop( array( 'bake_shipping_into_price' => '1' ) );
linked_product( 113, '2013', array( '_regular_price' => '20.00', '_sale_price' => '18.00', '_ebay_export_price' => '30.00', '_ebay_price_seen' => '30.00', '_ebay_baked_shipping' => '3.00' ) );
import( listing( '2013', '32.0', $FLAT . '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">40.0</OriginalRetailPrice></DiscountPriceInfo>' ) );
check( 'a markdown on eBay, postage added on import, no rule: the field takes eBay\'s price (40.00, before any postage); the website\'s prices and their postage stay', array( meta( 113, '_ebay_export_price' ), prices( 113 ), meta( 113, '_ebay_baked_shipping' ) ), array( '40.00', array( '20.00', '18.00' ), '3.00' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SHOP'S RULE: THE WEBSITE PRICE CHANGES ONLY WHEN EBAY'S HAS, AND NOT BY THE MARKUP\n" . str_repeat( '=', 124 ) . "\n";

shop( $PLUS10 );
$new = import( listing( '2020', '22.0' ) );
check( 'a new product, plus 10%: 22.00 on eBay is 20.00 on the website', array( prices( $new ), meta( $new, '_ebay_price_seen' ) ), array( array( '20.00', '' ), '22.00' ) );
linked_product( 120, '2021', array( '_regular_price' => '20.00' ) );
import( listing( '2021', '22.0' ) );
check( 'eBay\'s price is the rule\'s for the website price: nothing changes', array( prices( 120 )[0], price_lines( 120 ) ), array( '20.00', array( 'Price kept: eBay\'s price is the website price under the eBay price rule' ) ) );
linked_product( 121, '2022', array( '_regular_price' => '20.00' ) );
import( listing( '2022', '20.0' ) );
check( 'the rule just switched on - eBay still at the old 20.00, never noted: the website price kept, not taken down 10%', array( prices( 121 )[0], price_lines( 121 ), meta( 121, '_ebay_price_seen' ) ), array( '20.00', array( 'Price kept: eBay\'s price was not known before, so it is not taken as a change' ), '20.00' ) );
import( listing( '2022', '20.0' ) );
check( '  read again unchanged: kept', array( prices( 121 )[0], price_lines( 121 ) ), array( '20.00', array( 'Price kept: eBay\'s price has not changed since it was last sent or read' ) ) );
linked_product( 122, '2023', array( '_regular_price' => '25.00', '_ebay_price_seen' => '22.00' ) );
import( listing( '2023', '22.0' ) );
check( 'the website price changed here, its push still to come: eBay\'s unchanged price does not undo it', array( prices( 122 )[0], price_lines( 122 ) ), array( '25.00', array( 'Price kept: eBay\'s price has not changed since it was last sent or read' ) ) );
linked_product( 123, '2024', array( '_regular_price' => '20.00', '_sale_price' => '17.00', '_ebay_price_seen' => '22.00' ) );
import( listing( '2024', '33.0' ) );
check( 'eBay\'s price changed on eBay, 22.00 to 33.00: the website price is 30.00, its sale gone as eBay has none', array( prices( 123 ), price_lines( 123 ), meta( 123, '_ebay_price_seen' ) ), array( array( '30.00', '' ), array( 'Price updated (eBay won): eBay\'s 33.00 is the eBay price rule\'s price for 30.00' ), '33.00' ) );
shop( $PLUS10 + array( 'overwrite_price' => '0' ) );
linked_product( 124, '2025', array( '_regular_price' => '20.00', '_ebay_price_seen' => '22.00' ) );
import( listing( '2025', '33.0' ) );
check( 'Overwrite Price off: the website price kept whatever eBay did', array( prices( 124 )[0], price_lines( 124 ) ), array( '20.00', array( 'Price skipped (WooCommerce won)' ) ) );

// Rounding makes the reverse lossy.
shop( $PLUS10 + array( 'ebay_price_rounding' => '99' ) );
linked_product( 125, '2026', array( '_regular_price' => '20.00', '_ebay_price_seen' => '22.99' ) );
import( listing( '2026', '22.99' ) );
check( 'plus 10%, rounded to .99: 22.99 is 20.00\'s, nothing changes', array( prices( 125 )[0], price_lines( 125 ) ), array( '20.00', array( 'Price kept: eBay\'s price is the website price under the eBay price rule' ) ) );
import( listing( '2026', '27.99' ) );
check( '  eBay changed to 27.99: the website price nearest 27.99 / 1.1 that the rule gives 27.99 from, 25.44 (25.45 would give 28.99)', array( prices( 125 )[0], TCGiant_Sync_Ebay_Price::forward( 25.44, TCGiant_Sync_Ebay_Price::rule() ), TCGiant_Sync_Ebay_Price::forward( 25.45, TCGiant_Sync_Ebay_Price::rule() ) ), array( '25.44', '27.99', '28.99' ) );
$from = count( $LOG );
import( listing( '2026', '27.5' ) );
check( '  eBay changed to 27.50, which .99 rounding never gives: the exact reverse, 25.00, and the log says the next push sends 27.99', array( prices( 125 )[0], logged_since( $from ) ), array( '25.00', array( 'WC #125 (eBay listing 2026): eBay\'s price of 27.50 cannot come from the eBay price rule (its rounding never gives it), so the website price was set to 25.00, the nearest: the next push sends eBay 27.99. Give the product its own eBay price to keep 27.50.' ) ) );
import( listing( '2026', '27.5' ) );
check( '  read again: no change, said no more', array( prices( 125 )[0], count( logged_since( $from ) ) ), array( '25.00', 1 ) );
shop( array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10', 'ebay_price_rounding' => 'whole' ) );
$new = import( listing( '2027', '23.0' ) );
check( 'rounded up to a whole number, new product at 23.00: the website price nearest 20.91 that gives 23.00 - 20.91', array( prices( $new )[0], TCGiant_Sync_Ebay_Price::forward( 20.91, TCGiant_Sync_Ebay_Price::rule() ) ), array( '20.91', '23.00' ) );

// The amount, and both.
shop( array( 'ebay_price_rule' => 'fixed', 'ebay_price_fixed' => '2' ) );
$new = import( listing( '2028', '22.0' ) );
check( 'plus 2.00: 22.00 on eBay is 20.00 on the website', prices( $new )[0], '20.00' );
shop( array( 'ebay_price_rule' => 'fixed', 'ebay_price_fixed' => '-2' ) );
$new = import( listing( '2033', '18.0' ) );
check( '  a minus amount is no rule at all: eBay\'s 18.0, as before', prices( $new )[0], '18.0' );
shop( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '12.5', 'ebay_price_fixed' => '0.50' ) );
$new = import( listing( '2029', '23.0' ) );
check( 'plus 12.5% and 0.50: 23.00 is 20.00 (20 x 1.125 + 0.50)', prices( $new )[0], '20.00' );

// A markdown, and postage, under the rule.
shop( $PLUS10 );
$new = import( listing( '2030', '33.0', '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">44.0</OriginalRetailPrice></DiscountPriceInfo>' ) );
check( 'a markdown under the rule: 44.00 and 33.00 on eBay are 40.00 and 30.00 on the website', prices( $new ), array( '40.00', '30.00' ) );
shop( $PLUS10 + array( 'bake_shipping_into_price' => '1' ) );
$new = import( listing( '2031', '22.0', $FLAT ) );
check( 'postage added under the rule: 20.00 + 4.50, and the 4.50 recorded', array( prices( $new )[0], meta( $new, '_ebay_baked_shipping' ) ), array( '24.50', '4.50' ) );
import( listing( '2031', '22.0', $FLAT ) );
check( '  read again: the rule gives 22.00 from 24.50 less its 4.50 - nothing changes', array( prices( $new )[0], price_lines( $new ) ), array( '24.50', array( 'Price kept: eBay\'s price is the website price under the eBay price rule' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nVARIATIONS: EACH ONE AS A PRODUCT IS\n" . str_repeat( '=', 124 ) . "\n";

/** A variation listing: SKU => price. */
function variation_listing( $item_id, array $prices ) {
	$vars = '';
	foreach ( $prices as $sku => $price ) {
		$vars .= '<Variation><SKU>' . $sku . '</SKU><StartPrice currencyID="USD">' . $price . '</StartPrice><Quantity>2</Quantity><SellingStatus><QuantitySold>0</QuantitySold></SellingStatus>'
			. '<VariationSpecifics><NameValueList><Name>Size</Name><Value>' . substr( $sku, -1 ) . '</Value></NameValueList></VariationSpecifics></Variation>';
	}
	return listing( $item_id, '11.0', '<Variations>' . $vars . '</Variations>' );
}
/** The variation with this SKU. */
function variation_by_sku( $sku ) {
	return wc_get_product_id_by_sku( $sku );
}

shop();
$parent = import( variation_listing( '3001', array( 'V1-S' => '11.0', 'V1-L' => '13.75' ) ) );
$s      = variation_by_sku( 'V1-S' );
$l      = variation_by_sku( 'V1-L' );
check( 'a variation listing, no rule: each variation eBay\'s price, as before, and eBay\'s noted', array( prices( $s )[0], prices( $l )[0], meta( $s, '_ebay_price_seen' ), meta( $l, '_ebay_price_seen' ) ), array( '11.0', '13.75', '11.00', '13.75' ) );
check( '  the parent\'s own price as before, and nothing of 3.29.0\'s on it', array( prices( $parent )[0], meta( $parent, '_ebay_price_seen' ) ), array( '11.0', null ) );
shop( $PLUS10 );
$parent2 = import( variation_listing( '3002', array( 'V2-S' => '11.0' ) ) );
check( '  a new variation listing under the rule: its variation 10.00, the parent\'s own price as before (eBay\'s)', array( prices( variation_by_sku( 'V2-S' ) )[0], prices( $parent2 )[0] ), array( '10.00', '11.0' ) );
update_post_meta( $s, '_ebay_export_price', '12.00' );
update_post_meta( $s, '_ebay_price_seen', '12.00' );
update_post_meta( $l, '_regular_price', '12.50' );
import( variation_listing( '3001', array( 'V1-S' => '12.5', 'V1-L' => '13.75', 'V1-M' => '16.5' ) ) );
$m = variation_by_sku( 'V1-M' );
check( 'one variation\'s own eBay price changed on eBay: that field takes it, its website price left', array( meta( $s, '_ebay_export_price' ), prices( $s )[0] ), array( '12.50', '11.0' ) );
check( '  another under the rule at 13.75, 12.50\'s: left', prices( $l )[0], '12.50' );
check( '  a new variation at 16.50: 15.00 on the website', array( prices( $m )[0], meta( $m, '_ebay_price_seen' ) ), array( '15.00', '16.50' ) );
update_post_meta( $l, '_ebay_price_seen', '13.75' );
import( variation_listing( '3001', array( 'V1-S' => '12.5', 'V1-L' => '15.4', 'V1-M' => '16.5' ) ) );
check( '  the one under the rule changed on eBay to 15.40: 14.00 on the website', prices( $l )[0], '14.00' );
shop( $PLUS10 + array( 'overwrite_price' => '0' ) );
import( variation_listing( '3001', array( 'V1-S' => '13.0', 'V1-L' => '16.5', 'V1-M' => '16.5' ) ) );
check( '  Overwrite Price off: no variation\'s prices, nor its own eBay price', array( meta( $s, '_ebay_export_price' ), prices( $l )[0] ), array( '12.50', '14.00' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAUCTIONS\n" . str_repeat( '=', 124 ) . "\n";

/** An auction's answer: its opening bid, if eBay sent one, and its current bid. */
function auction_listing( $item_id, $start, $current, $bids ) {
	return ebay_item( '<ItemID>' . $item_id . '</ItemID><Title>Auction ' . $item_id . '</Title><Currency>USD</Currency><Site>US</Site>'
		. '<Quantity>1</Quantity><ListingType>Chinese</ListingType><ListingDuration>Days_7</ListingDuration>'
		. ( '' !== $start ? '<StartPrice currencyID="USD">' . $start . '</StartPrice>' : '' )
		. '<ListingDetails><StartTime>2026-10-30T10:00:00.000Z</StartTime><EndTime>2026-11-06T10:00:00.000Z</EndTime></ListingDetails>'
		. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><BidCount>' . $bids . '</BidCount><CurrentPrice currencyID="USD">' . $current . '</CurrentPrice></SellingStatus>' );
}
shop( $PLUS10 );
$auction = import( auction_listing( '4001', '37.4', '61.0', 4 ) );
check( 'a new imported auction under the rule: its opening bid 37.40 is 34.00 on the website, never the current bid', array( prices( $auction )[0], meta( $auction, '_ebay_price_seen' ), meta( $auction, '_ebay_start_price' ) ), array( '34.00', '37.40', '37.4' ) );
import( auction_listing( '4001', '', '75.0', 6 ) );
check( '  an answer with only its current bid: the price kept, and the bid not noted as eBay\'s price', array( prices( $auction )[0], meta( $auction, '_ebay_price_seen' ) ), array( '34.00', '37.40' ) );
shop();
$auction = import( auction_listing( '4002', '37.4', '61.0', 4 ) );
check( 'no rule: its opening bid, as before', prices( $auction )[0], '37.4' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTAX: TAKEN OFF AGAIN WHERE A PUSH ADDS IT\n" . str_repeat( '=', 124 ) . "\n";

$TAX = array( 'enabled' => true, 'inclusive' => false, 'rates' => array( '' => array( 1 => array( 'rate' => '20.0000' ) ), 'reduced-rate' => array( 2 => array( 'rate' => '5.0000' ) ) ) );
shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
$new = import( listing( '5001', '24.0' ) );
check( 'eBay UK, Auto, prices entered without tax: 24.00 on eBay is 20.00 on the website', prices( $new )[0], '20.00' );
linked_product( 130, '5002', array( '_regular_price' => '20.00' ) );
import( listing( '5002', '24.0' ) );
check( '  20.00 on the website is 24.00 with tax: nothing changes', array( prices( 130 )[0], price_lines( 130 ) ), array( '20.00', array( 'Price kept: eBay\'s price is the website price under the eBay price rule' ) ) );
linked_product( 131, '5003', array( '_regular_price' => '20.00', '_tax_class' => 'reduced-rate', '_ebay_price_seen' => '21.00' ) );
import( listing( '5003', '26.25' ) );
check( '  a product in the reduced-rate class (5%), eBay changed to 26.25: 25.00', prices( 131 )[0], '25.00' );
linked_product( 132, '5004', array( '_regular_price' => '20.00', '_tax_status' => 'none' ) );
import( listing( '5004', '24.0' ) );
check( '  a product that is not taxable: as before, eBay\'s price', prices( 132 )[0], '24.0' );
$TAX['inclusive'] = true;
linked_product( 133, '5005', array( '_regular_price' => '20.00' ) );
import( listing( '5005', '24.0' ) );
check( 'prices entered with tax: nothing to take off - as before', prices( 133 )[0], '24.0' );
$TAX['inclusive'] = false;
shop( array( 'marketplace' => 'EBAY_US', 'ebay_price_tax' => 'auto' ) );
linked_product( 134, '5006', array( '_regular_price' => '20.00' ) );
import( listing( '5006', '24.0' ) );
check( 'eBay US on Auto: no tax - as before', prices( 134 )[0], '24.0' );
shop( array( 'marketplace' => 'EBAY_CA', 'ebay_price_tax' => 'yes' ) );
linked_product( 136, '5011', array( '_regular_price' => '20.00' ) );
import( listing( '5011', '24.0' ) );
check( '  eBay Canada on Yes: no tax either, as before (3.29.0 re-verify)', prices( 136 )[0], '24.0' );
shop( array( 'marketplace' => 'EBAY_GB' ) );
linked_product( 135, '5007', array( '_regular_price' => '20.00' ) );
import( listing( '5007', '24.0' ) );
check( 'eBay UK with the setting never chosen (No): as before', prices( 135 )[0], '24.0' );
shop( array( 'marketplace' => 'EBAY_AU', 'ebay_price_tax' => 'auto' ) + $PLUS10 );
$TAX['rates'][''] = array( 1 => array( 'rate' => '10.0000' ) );
$new = import( listing( '5008', '24.2' ) );
check( 'eBay Australia, Auto, 10% GST and plus 10%: 24.20 is 20.00 (20 x 1.1 x 1.1)', prices( $new )[0], '20.00' );
shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
$TAX['rates'][''] = array( 1 => array( 'rate' => '20.0000' ) );
$from = count( $LOG );
$new  = import( listing( '5009', '10.05' ) );
check( 'tax alone, 20%: eBay\'s 10.05, which no website price in whole cents gives - 8.38, within half a cent of 8.375, and the log names tax as why, and says the next push sends 10.06', array( prices( $new )[0], logged_since( $from ) ), array( '8.38', array( 'A product made from eBay (eBay listing 5009): eBay\'s price of 10.05 is not one that 20% tax gives from a website price in whole cents, so the website price was set to 8.38, the nearest: the next push sends eBay 10.06. Give the product its own eBay price to keep 10.05.' ) ) );
$from = count( $LOG );
$new  = import( listing( '5010', '10.06' ) );
check( '  10.06, which 8.38 gives: 8.38, nothing said', array( prices( $new )[0], logged_since( $from ) ), array( '8.38', array() ) );
$TAX = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNO WEBSITE PRICE OF 0.99 OR MORE GIVES EBAY'S: NONE FROM THE REVERSE\n" . str_repeat( '=', 124 ) . "\n";

$PLUS1 = array( 'ebay_price_rule' => 'fixed', 'ebay_price_fixed' => '1.00' );
foreach ( array( array( '0.99', '1.99', '6001', '6011' ), array( '1.00', '2.00', '6002', '6012' ), array( '1.01', '2.01', '6003', '6013' ), array( '1.98', '2.98', '6004', '6014' ) ) as list( $at, $next, $on, $off ) ) {
	shop( $PLUS1 );
	$from = count( $LOG );
	$new  = import( listing( $on, $at ) );
	check( 'plus 1.00, a new product from eBay at ' . $at . ': never a website price of cents - eBay\'s ' . $at . ' is its website price, and the log says why and what the next push sends', array( prices( $new ), logged_since( $from ) ), array( array( $at, '' ), array( 'A product made from eBay (eBay listing ' . $on . '): No website price of 0.99 or more gives eBay\'s price of ' . $at . ' under the eBay price rule (plus 1.00): the new product takes eBay\'s ' . $at . ' as its website price, and the next push sends eBay ' . $next . '. Give the product its own eBay price to keep ' . $at . '.' ) ) );
	shop( $PLUS1 + array( 'overwrite_price' => '0' ) );
	$new = import( listing( $off, $at ) );
	check( '  the same with Overwrite Price off (a new product always takes a price)', prices( $new )[0], $at );
}
shop( $PLUS1 );
$from = count( $LOG );
$new  = import( listing( '6020', '1.99' ) );
check( 'plus 1.00, a new product at 1.99: 0.99, the website price the rule turns into 1.99 exactly, nothing said', array( prices( $new )[0], logged_since( $from ) ), array( '0.99', array() ) );
linked_product( 140, '6030', array( '_regular_price' => '5.00', '_ebay_price_seen' => '6.00' ) );
$from = count( $LOG );
import( listing( '6030', '0.95' ) );
check( 'a product here whose eBay price the seller lowered from 6.00 to 0.95: its website price kept at 5.00, and why', array( prices( 140 )[0], price_lines( 140 ), logged_since( $from ) ), array( '5.00', array( 'Price kept: no website price of 0.99 or more gives eBay\'s price of 0.95 under the eBay price rule (plus 1.00)' ), array( 'WC #140 (eBay listing 6030): No website price of 0.99 or more gives eBay\'s price of 0.95 under the eBay price rule (plus 1.00): the website price is kept at 5.00 and the next push sends eBay 6.00. Give the product its own eBay price to keep 0.95.' ) ) );
linked_product( 143, '6034', array( '_regular_price' => '5.00', '_sale_price' => '4.50', '_ebay_price_seen' => '6.00' ) );
$from = count( $LOG );
import( listing( '6034', '1.01' ) );
check( '  one lowered to 1.01, which 0.01 would give (3.29.0 re-verify): 5.00 and its sale price kept, and said', array( prices( 143 ), logged_since( $from ) ), array( array( '5.00', '4.50' ), array( 'WC #143 (eBay listing 6034): No website price of 0.99 or more gives eBay\'s price of 1.01 under the eBay price rule (plus 1.00): the website price is kept at 5.00, with its sale price of 4.50, and the next push sends eBay 6.00. Give the product its own eBay price to keep 1.01.' ) ) );
check( '  eBay\'s 0.95 noted, so the next read is no change', meta( 140, '_ebay_price_seen' ), '0.95' );
import( listing( '6030', '0.95' ) );
check( '  read again: kept, said no more', array( prices( 140 )[0], price_lines( 140 ) ), array( '5.00', array( 'Price kept: eBay\'s price has not changed since it was last sent or read' ) ) );
shop( $PLUS1 + array( 'overwrite_price' => '0' ) );
linked_product( 141, '6031', array( '_regular_price' => '5.00', '_ebay_price_seen' => '6.00' ) );
import( listing( '6031', '0.99' ) );
check( '  Overwrite Price off: kept, as for any eBay change', array( prices( 141 )[0], price_lines( 141 ) ), array( '5.00', array( 'Price skipped (WooCommerce won)' ) ) );
shop( $PLUS1 );
$markdown = '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">5.0</OriginalRetailPrice></DiscountPriceInfo>';
$from     = count( $LOG );
$new      = import( listing( '6032', '0.80', $markdown ) );
check( 'a markdown from 5.00 to 0.80, a new product: eBay\'s 5.00 and 0.80 both, never 4.00 with a sale price from eBay, and said', array( prices( $new ), logged_since( $from ) ), array( array( '5.00', '0.80' ), array( 'A product made from eBay (eBay listing 6032): No website price of 0.99 or more gives eBay\'s markdown price of 0.80 under the eBay price rule (plus 1.00): the new product takes eBay\'s 5.00 as its website price and eBay\'s 0.80 as its sale price, and the next push sends eBay 6.00. Give the product its own eBay price to keep 5.00.' ) ) );
linked_product( 142, '6033', array( '_regular_price' => '9.00', '_sale_price' => '3.00', '_ebay_price_seen' => '10.00' ) );
import( listing( '6033', '0.80', $markdown ) );
check( '  a product here, eBay changed to 5.00 marked down to 0.80: both its prices kept, 9.00 and 3.00', prices( 142 ), array( '9.00', '3.00' ) );
shop( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '1.00' ) );
$new = import( listing( '6035', '0.99', '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">1.05</OriginalRetailPrice></DiscountPriceInfo>' ) );
check( 'plus 10% and 1.00, eBay 1.05 marked down to 0.99, a new product: 1.05 and 0.99, never 0.05 with a sale price of 0.99 above it (3.29.0 re-verify)', prices( $new ), array( '1.05', '0.99' ) );
$new = import( listing( '6036', '2.00', '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">3.20</OriginalRetailPrice></DiscountPriceInfo>' ) );
check( '  eBay 3.20 marked down to 2.00 (2.00 and 0.91 from the reverse): eBay\'s 3.20 and 2.00, the sale below the price', prices( $new ), array( '3.20', '2.00' ) );
shop( $PLUS1 );
shop( $PLUS1 + array( 'overwrite_price' => '0' ) );
$parent3 = import( variation_listing( '3003', array( 'V3-S' => '0.99', 'V3-L' => '3.0' ) ) );
check( 'variations, Overwrite Price off: a new one at 0.99 takes eBay\'s 0.99, one at 3.00 is 2.00', array( prices( variation_by_sku( 'V3-S' ) )[0], prices( variation_by_sku( 'V3-L' ) )[0] ), array( '0.99', '2.00' ) );
shop( $PLUS1 );
update_post_meta( variation_by_sku( 'V3-L' ), '_ebay_price_seen', '3.00' );
import( variation_listing( '3003', array( 'V3-S' => '0.99', 'V3-L' => '1.00' ) ) );
check( '  one here lowered on eBay to 1.00: its website price kept at 2.00', prices( variation_by_sku( 'V3-L' ) )[0], '2.00' );
update_post_meta( variation_by_sku( 'V3-L' ), '_ebay_price_seen', '1.00' );
import( variation_listing( '3003', array( 'V3-S' => '0.99', 'V3-L' => '1.05', 'V3-M' => '1.50' ) ) );
check( '  lowered again to 1.05 (0.05 would give it): kept at 2.00; a new one at 1.50 takes eBay\'s 1.50, never 0.50', array( prices( variation_by_sku( 'V3-L' ) )[0], prices( variation_by_sku( 'V3-M' ) )[0] ), array( '2.00', '1.50' ) );
check( 'nowhere a website price below 0.99 but eBay\'s own markdown of 0.80, taken as it is', array_values( array_unique( array_filter( array_merge( array_column( $META, '_regular_price' ), array_column( $META, '_sale_price' ) ), function ( $price ) { return is_numeric( $price ) && (float) $price < 0.99; } ) ) ), array( '0.80' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTAX OR A PERCENTAGE ALONE: A SMALL WEBSITE PRICE STANDS (3.29.0 SPOT CHECK, S1)\n" . str_repeat( '=', 124 ) . "\n";

// Only a fixed amount takes the reverse towards nothing. With tax or a
// percentage alone a website price under 0.99 is eBay's own price less the
// tax or the markup, and the import writes it, so the next push sends eBay
// what it had.
$TAX = array( 'enabled' => true, 'inclusive' => false, 'rates' => array( '' => array( 1 => array( 'rate' => '20.0000' ) ) ) );
shop( array( 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
$from = count( $LOG );
$at99 = import( listing( '6101', '0.99' ) );
$at110 = import( listing( '6102', '1.10' ) );
check( 'eBay UK, 20% tax alone, new products at 0.99 and 1.10: 0.83 (which goes back as 1.00, said) and 0.92 - never eBay\'s own price, which the next push would raise by 20%', array( prices( $at99 )[0], prices( $at110 )[0], logged_since( $from ) ), array( '0.83', '0.92', array( 'A product made from eBay (eBay listing 6101): eBay\'s price of 0.99 is not one that 20% tax gives from a website price in whole cents, so the website price was set to 0.83, the nearest: the next push sends eBay 1.00. Give the product its own eBay price to keep 0.99.' ) ) );
linked_product( 170, '6103', array( '_regular_price' => '2.49', '_ebay_price_seen' => '2.99' ) );
import( listing( '6103', '1.10' ) );
check( '  a product here whose seller cut eBay\'s 2.99 to 1.10: 0.92, so the next push keeps the cut', array( prices( 170 )[0], price_lines( 170 ) ), array( '0.92', array( 'Price updated (eBay won): eBay\'s 1.10 is the eBay price rule\'s price for 0.92' ) ) );
$new = import( listing( '6104', '0.99', '<DiscountPriceInfo><OriginalRetailPrice currencyID="USD">6.0</OriginalRetailPrice></DiscountPriceInfo>' ) );
check( '  a new product, eBay 6.00 marked down to 0.99: 5.00 with a sale of 0.83, never 6.00 with 0.99', prices( $new ), array( '5.00', '0.83' ) );
$TAX = array( 'enabled' => false, 'inclusive' => false, 'rates' => array() );
shop( $PLUS10 );
$at99  = import( listing( '6105', '0.99' ) );
$at110 = import( listing( '6106', '1.10' ) );
check( 'plus 10% alone, new products at 0.99 and 1.10: 0.90 and 1.00, exactly', array( prices( $at99 )[0], prices( $at110 )[0] ), array( '0.90', '1.00' ) );
shop( $PLUS1 );
$at99  = import( listing( '6107', '0.99' ) );
$at110 = import( listing( '6108', '1.10' ) );
check( 'plus 1.00, new products at 0.99 and 1.10: eBay\'s own 0.99 and 1.10 - the fixed amount\'s floor', array( prices( $at99 )[0], prices( $at110 )[0] ), array( '0.99', '1.10' ) );
shop( array( 'ebay_price_rule' => 'both', 'ebay_price_percent' => '10', 'ebay_price_fixed' => '0.50' ) );
$new = import( listing( '6109', '1.10' ) );
check( 'plus 10% and 0.50, new at 1.10: eBay\'s 1.10 - any fixed amount above 0 has the floor', prices( $new )[0], '1.10' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nLEAVING THE FEATURE: A RULE SWITCHED OFF, AN EBAY PRICE OF ITS OWN CLEARED\n" . str_repeat( '=', 124 ) . "\n";

$RULE99 = array( 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '10', 'ebay_price_rounding' => '99' );
shop( $RULE99 );
linked_product( 150, '7001', array( '_regular_price' => '10.00' ) );
import( listing( '7001', '11.99' ) );
check( 'under plus 10% to .99: eBay\'s 11.99 is 10.00\'s, noted, and marked as the rule\'s', array( prices( 150 )[0], meta( 150, '_ebay_price_seen' ), meta( 150, '_ebay_price_marked' ) ), array( '10.00', '11.99', '1' ) );
shop();
import( listing( '7001', '11.99' ) );
check( 'the rule switched off, eBay still at 11.99: the website price stays 10.00 - not 11.99 - and the product\'s log says why', array( prices( 150 )[0], price_lines( 150 ), meta( 150, '_ebay_price_marked' ) ), array( '10.00', array( 'Price kept: eBay\'s price is still the one the eBay price rule, tax or an eBay price of its own made, and has not changed on eBay since' ), '1' ) );
shop( array( 'overwrite_price' => '0' ) );
import( listing( '7001', '11.99' ) );
check( '  Overwrite Price off meanwhile: as before, and still marked', array( prices( 150 )[0], price_lines( 150 ), meta( 150, '_ebay_price_marked' ) ), array( '10.00', array( 'Price skipped (WooCommerce won)' ), '1' ) );
shop();
import( listing( '7001', '13.0' ) );
check( '  the seller then changes it on eBay to 13.00: that is the website\'s (eBay won, as before), no longer marked', array( prices( 150 )[0], price_lines( 150 ), meta( 150, '_ebay_price_marked' ) ), array( '13.0', array( 'Price updated (eBay won)' ), null ) );
shop( $PLUS10 );
linked_product( 151, '7002', array( '_regular_price' => '10.00', '_ebay_export_price' => '15.00' ) );
import( listing( '7002', '15.0' ) );
delete_post_meta( 151, '_ebay_export_price' );
shop();
import( listing( '7002', '15.0' ) );
check( 'an eBay price of its own, 15.00, cleared, eBay still at 15.00: the website price stays 10.00, not 15.00', array( prices( 151 )[0], price_lines( 151 ) ), array( '10.00', array( 'Price kept: eBay\'s price is still the one the eBay price rule, tax or an eBay price of its own made, and has not changed on eBay since' ) ) );
linked_product( 152, '7003', array( '_regular_price' => '10.00', '_ebay_price_seen' => '9.00' ) );
import( listing( '7003', '9.0' ) );
check( 'a product that never had a rule or a price of its own, eBay unchanged at 9.00: eBay\'s 9.0, as 3.28.8 wrote it', array( prices( 152 )[0], price_lines( 152 ) ), array( '9.0', array( 'Price updated (eBay won)' ) ) );
shop( $RULE99 );
$parent4 = import( variation_listing( '3004', array( 'V4-S' => '11.0' ) ) );
update_post_meta( variation_by_sku( 'V4-S' ), '_regular_price', '10.00' );
import( variation_listing( '3004', array( 'V4-S' => '11.99' ) ) );
shop();
import( variation_listing( '3004', array( 'V4-S' => '11.99' ) ) );
check( '  a variation, the rule switched off: 10.00 kept too', prices( variation_by_sku( 'V4-S' ) )[0], '10.00' );

shop( array( 'bake_shipping_into_price' => '1' ) );
linked_product( 154, '7005', array( '_regular_price' => '20.00' ) );
import( listing( '7005', '20.0', $FLAT ) );
shop();
import( listing( '7005', '20.0', $FLAT ) );
check( '"Add eBay Shipping Cost" turned off: the import writes 20.0 without postage, and the record of the 4.50 goes with it', array( prices( 154 )[0], meta( 154, '_ebay_baked_shipping' ) ), array( '20.0', null ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE IMPORT SETTINGS' FINGERPRINT (3.28.6'S LIGHT PATH)\n" . str_repeat( '=', 124 ) . "\n";

/** The fingerprint as 3.28.8 made it. */
function old_fingerprint() {
	$picked = array();
	foreach ( TCGiant_Sync_Mapper::IMPORT_SETTING_KEYS as $key ) {
		$picked[ $key ] = $GLOBALS['SETTINGS'][ $key ] ?? null;
	}
	$picked['woocommerce_weight_unit']    = get_option( 'woocommerce_weight_unit', 'lbs' );
	$picked['woocommerce_dimension_unit'] = get_option( 'woocommerce_dimension_unit', 'in' );
	return md5( serialize( $picked ) );
}
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0' ) );
$before = TCGiant_Sync_Mapper::settings_fingerprint();
check( 'no rule: the fingerprint 3.28.8 made, so no renewal is read in full for nothing', $before, old_fingerprint() );
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0', 'ebay_price_rule' => 'none', 'ebay_price_percent' => '10', 'ebay_price_tax' => 'no' ) );
check( '  nor with no rule but a percentage left in its box, and tax No', TCGiant_Sync_Mapper::settings_fingerprint(), $before );
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0' ) + $PLUS10 );
$with = TCGiant_Sync_Mapper::settings_fingerprint();
check( 'a rule: another fingerprint, so each listing is read in full once', $with !== $before, true );
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0', 'ebay_price_rule' => 'percent', 'ebay_price_percent' => '12' ) );
check( '  another rule: another again', ! in_array( TCGiant_Sync_Mapper::settings_fingerprint(), array( $before, $with ), true ), true );
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0', 'marketplace' => 'EBAY_GB', 'ebay_price_tax' => 'auto' ) );
$gb = TCGiant_Sync_Mapper::settings_fingerprint();
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0', 'marketplace' => 'EBAY_GB' ) );
check( '  tax on Auto on eBay UK: another', $gb !== TCGiant_Sync_Mapper::settings_fingerprint(), true );
shop( array( 'overwrite_price' => '1', 'bake_shipping_into_price' => '0', 'ebay_price_tax' => 'yes' ) );
check( '  tax on Yes on eBay US, where nothing is added: the fingerprint 3.28.8 made (3.29.0 re-verify)', TCGiant_Sync_Mapper::settings_fingerprint(), $before );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA DUPLICATE OR AN UNLINK\n" . str_repeat( '=', 124 ) . "\n";

$identity = TCGiant_Sync_Listing_Link::listing_identity_meta_keys();
check( 'eBay\'s price is the listing\'s: a copy or an unlinked product drops it and its mark; its own eBay price and the postage in its website price go with it', array( in_array( '_ebay_price_seen', $identity, true ), in_array( '_ebay_price_marked', $identity, true ), in_array( '_ebay_export_price', $identity, true ), in_array( '_ebay_baked_shipping', $identity, true ) ), array( true, true, false, false ) );
linked_product( 160, '8001', array( '_ebay_price_seen' => '11.99', '_ebay_price_marked' => '1' ), true );
variation_of( 1601, 160, 'V8-S', array( '_ebay_price_seen' => '11.99', '_ebay_price_marked' => '1', '_ebay_export_price' => '12.00', '_ebay_baked_shipping' => '1.50' ) );
variation_of( 1602, 160, 'V8-L', array( '_ebay_price_seen' => '13.99' ) );
TCGiant_Sync_Listing_Link::unlink_product_from_ebay( 160, 'unlinked by admin' );
check( 'Unlink of a variable product: its variations\' records of eBay\'s price and their marks go too (3.29.0 re-verify); their own eBay prices and postage stay', array( meta( 160, '_ebay_price_seen' ), meta( 1601, '_ebay_price_seen' ), meta( 1601, '_ebay_price_marked' ), meta( 1602, '_ebay_price_seen' ), meta( 1601, '_ebay_export_price' ), meta( 1601, '_ebay_baked_shipping' ) ), array( null, null, null, null, '12.00', '1.50' ) );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
check( 'deleting the plugin with its data deletes the records of eBay\'s price and their marks; the shop\'s own eBay prices and the postage in its prices stay (3.29.0 review, finding 11)', array( false !== strpos( $uninstall, "'_ebay_live', '_ebay_price_seen', '_ebay_price_marked'" ), false !== strpos( $uninstall, "'_ebay_export_price'" ), false !== strpos( $uninstall, "'_ebay_baked_shipping'" ) ), array( true, false, false ) );

$pass_total = $pass;
printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
