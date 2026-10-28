<?php
/**
 * Harness: a re-import never wraps one template inside another.
 *
 * A templated description carries a marker round its content region -
 * <div class="tcg-desc" data-tcg="content"> - and a comment naming the
 * template and version. Importing such a listing (a new product, or with
 * Overwrite Description on) takes only that region, so the next push puts it
 * back inside the template rather than a design inside a design - but only
 * with proof that TCGiant sent it with a template: the comment and the exact
 * marker, or the linked product's record of a template sent. A listing
 * without that proof - a WP-Lister one, or a shop's own "tcg-desc" class -
 * imports exactly as before, and pushing it with a template raises the
 * nested-template lint.
 *
 * Loaded for real: the templates (content_region() and render), the
 * sanitiser, and the mapper's map_ebay_to_woo().
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

function get_post_status( $id ) { return 'publish'; }
function wc_get_product_id_by_sku( $sku ) { return 0; }
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-mapper.php';

$region = function ( $description ) {
	return TCGiant_Sync_Templates::content_region( $description );
};
$imported = function ( $description, $item_id = '110000000091' ) {
	$data = ( new TCGiant_Sync_Mapper() )->map_ebay_to_woo( array( 'ItemID' => $item_id, 'Title' => 'Card', 'Description' => $description, 'Quantity' => '1', 'SellingStatus' => array( 'QuantitySold' => '0' ) ) );
	return $data['description'];
};

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWITH OUR MARKER: ONLY THE CONTENT REGION\n" . str_repeat( '=', 124 ) . "\n";

$ours = '<style>.box > p { color: red }</style><div style="max-width:700px"><h1>Charizard</h1>'
	. '<div class="tcg-desc" data-tcg="content"><p>Holo rare.</p><div class="note"><div>Light play.</div></div><p>Ships fast.</p></div>'
	. '<div class="footer"><p>Returns accepted within 30 days.</p></div></div><!--tcg:tpl=12.4-->';
check( 'the content region, nested divs and all', $region( $ours ), '<p>Holo rare.</p><div class="note"><div>Light play.</div></div><p>Ships fast.</p>' );
check( 'the import takes only that', $imported( $ours ), '<p>Holo rare.</p><div class="note"><div>Light play.</div></div><p>Ships fast.</p>' );

$no_data = str_replace( ' data-tcg="content"', '', $ours );
check( 'eBay dropped the data-* attribute: the class alone finds it, for a product that recorded a template sent', TCGiant_Sync_Templates::content_region( $no_data, true ), '<p>Holo rare.</p><div class="note"><div>Light play.</div></div><p>Ships fast.</p>' );
check( '  and without that record, the class alone proves nothing: as it came', $region( $no_data ), $no_data );
check( 'the comment alone, without the marker: as it came', $region( str_replace( ' data-tcg="content"', ' data-x="1"', $ours ) ), str_replace( ' data-tcg="content"', ' data-x="1"', $ours ) );
check( 'the marker without the comment: as it came', $region( str_replace( '<!--tcg:tpl=12.4-->', '', $ours ) ), str_replace( '<!--tcg:tpl=12.4-->', '', $ours ) );
check( '  but with the product\'s record, cut', TCGiant_Sync_Templates::content_region( str_replace( '<!--tcg:tpl=12.4-->', '', $ours ), true ), '<p>Holo rare.</p><div class="note"><div>Light play.</div></div><p>Ships fast.</p>' );
$quoted = str_replace( 'class="tcg-desc" data-tcg="content"', "data-tcg='content' class='tcg-desc extra'", $ours );
check( '  single quotes, or another class beside it: the class token, with the product\'s record', TCGiant_Sync_Templates::content_region( $quoted, true ), '<p>Holo rare.</p><div class="note"><div>Light play.</div></div><p>Ships fast.</p>' );
check( '  but the marker itself only as we write it, in double quotes (push-path round 2, P3)', $region( $quoted ), $quoted );

// A round trip: what a push sends, read back, is what it was made from.
$p   = new WC_Product( 91, array( 'name' => 'Charizard', 'description' => "<p>Holo rare.</p>\n<p>Ships <strong>fast</strong>.</p>" ) );
$tpl = make_template( array( 'html' => '<div><h1>{{title}}</h1>{{{description}}}<div class="footer">{{{policy.returns}}}</div></div>' ) );
update_post_meta( 91, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
$sent = described( $p );
check( 'a description a push made, read back: the content it was made from', $region( $sent['html'] ), "<p>Holo rare.</p>\n<p>Ships <strong>fast</strong>.</p>" );
$p->data['description'] = $imported( $sent['html'] );
check( '  so pushing it again sends the same bytes, not a template inside a template', described( $p )['html'], $sent['html'] );
check( '  and the version is in the comment', false !== strpos( $sent['html'], '<!--tcg:tpl=' . $tpl . '.1-->' ), true );

$planted = new WC_Product( 93, array( 'name' => 'Planted', 'description' => '<div class="tcg-desc" data-tcg="content"><p>Planted.</p></div><p>Real.</p>' ) );
update_post_meta( 93, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
$planted_sent = described( $planted );
check( 'a description cannot bring a marker of its own: one marker in what is sent', substr_count( $planted_sent['html'], 'data-tcg=' ), 1 );
check( '  so reading it back gives the whole content', false !== strpos( $region( $planted_sent['html'] ), 'Real.' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNEVER SENT WITH A TEMPLATE: EXACTLY AS 3.27.7 IMPORTED IT\n" . str_repeat( '=', 124 ) . "\n";

// A shop's own descriptions that happen to use the same words (push-path
// review, H1): 3.27.7 kept each of these whole, and so must this.
$theirs = array(
	'its own tcg-desc class between a header and a footer' => '<div class="header">My shop</div><div class="tcg-desc">The card itself.</div><div class="footer">Returns: 30 days</div>',
	'a class that only contains the word'                  => '<div class="my-tcg-desc-box"><h2>Pikachu</h2><p>NM</p></div><p>Shipping: tracked.</p>',
	'a tcg-desc-wrapper class'                             => '<div class="tcg-desc-wrapper"><p>Body.</p></div><div class="footer">Footer.</div>',
	'TCG-DESC in capitals, single quotes'                  => "<DIV CLASS='TCG-DESC'>Upper</DIV><P>after</P>",
	'a data-tcg="content" of its own, no comment'          => '<section><div data-tcg="content">Kept</div><div>Lost footer</div></section>',
	'the div entity-escaped, not in CDATA'                 => '&lt;div class="tcg-desc" data-tcg="content"&gt;Inner&lt;/div&gt;&lt;p&gt;Footer&lt;/p&gt;',
);
foreach ( $theirs as $label => $description ) {
	check( 'imported byte for byte: ' . $label, $imported( $description, '110000000095' ), $description );
}

// Linked to a product, the product decides: one that never recorded a
// template sent proves nothing; one that did, by the exact class token only.
$GLOBALS['W']['meta'][95] = array( '_ebay_item_id' => '110000000095', '_tcgiant_tpl_id' => '0', '_tcgiant_tpl_item' => '110000000095' );
check( 'a linked product whose last send had no template: still as it came', $imported( $theirs['its own tcg-desc class between a header and a footer'], '110000000095' ), $theirs['its own tcg-desc class between a header and a footer'] );
$GLOBALS['W']['meta'][95]['_tcgiant_tpl_id']   = '7';
$GLOBALS['W']['meta'][95]['_tcgiant_tpl_item'] = '110000000095';
check( 'one whose last send had a template: its region, by the class token', $imported( $theirs['its own tcg-desc class between a header and a footer'], '110000000095' ), 'The card itself.' );
check( '  never by a class that only contains the word', $imported( $theirs['a class that only contains the word'], '110000000095' ), $theirs['a class that only contains the word'] );
check( '  nor in capitals', $imported( $theirs['TCG-DESC in capitals, single quotes'], '110000000095' ), $theirs['TCG-DESC in capitals, single quotes'] );
// A record of a send to the listing before (it ended, and the product was
// linked to one the seller made by hand): no proof for this one (P1).
$GLOBALS['W']['meta'][95]['_tcgiant_tpl_item'] = '110000000001';
check( 'a template record of another listing proves nothing for this one', $imported( $theirs['its own tcg-desc class between a header and a footer'], '110000000095' ), $theirs['its own tcg-desc class between a header and a footer'] );
TCGiant_Sync_Templates::forget_sent( 95 );
check( '  and forget_sent() takes the whole record off', array_values( array_intersect( array_keys( $GLOBALS['W']['meta'][95] ?? array() ), TCGiant_Sync_Templates::SENT_META ) ), array() );
$mapper_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-mapper.php' );
$admin_src  = file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
$export_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
check( '  a re-link by SKU forgets it, the Link box does for another listing, a relist carries it to the new number', array( false !== strpos( $mapper_src, "update_post_meta( \$sku_product_id, '_ebay_item_id', \$item_id );\n\t\t\t\t\t\t// What was sent to the old listing is not this one's.\n\t\t\t\t\t\tif ( class_exists( 'TCGiant_Sync_Templates' ) ) {\n\t\t\t\t\t\t\tTCGiant_Sync_Templates::forget_sent( \$sku_product_id );" ), false !== strpos( $admin_src, "(string) \$product->get_meta( '_tcgiant_tpl_item' ) !== (string) \$item_id" ), false !== strpos( $export_src, "update_post_meta( \$product_id, '_tcgiant_tpl_item', \$new_id );" ) ), array( true, true, true ) );
unset( $GLOBALS['W']['meta'][95] );
check( 'the class token in capitals is no marker, even with the product\'s record', TCGiant_Sync_Templates::content_region( "<DIV CLASS='TCG-DESC'>Upper</DIV><P>after</P>", true ), "<DIV CLASS='TCG-DESC'>Upper</DIV><P>after</P>" );
check( 'nor data-tcg in single quotes, even with our comment', $region( "<div data-tcg='content'>x</div><p>y</p><!--tcg:tpl=1.1-->" ), "<div data-tcg='content'>x</div><p>y</p><!--tcg:tpl=1.1-->" );
check( 'nor data-tcg in another case, or unquoted, even with our comment', array( $region( '<div data-tcg="Content">x</div><p>y</p><!--tcg:tpl=1.1-->' ), $region( '<div data-tcg=content>x</div><p>y</p><!--tcg:tpl=1.1-->' ) ), array( '<div data-tcg="Content">x</div><p>y</p><!--tcg:tpl=1.1-->', '<div data-tcg=content>x</div><p>y</p><!--tcg:tpl=1.1-->' ) );

// The short description and the blocks are cleaned before the marker is
// allowed, so none of them can plant a region of its own (security, 3).
$short = new WC_Product( 96, array( 'name' => 'Box', 'description' => '<p>The real one.</p>', 'short_description' => '<div class="tcg-desc" data-tcg="content"><p>Planted.</p></div>' ) );
update_post_meta( 96, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<div>{{{short_description}}}</div>{{{description}}}' ) ) );
$short_sent = described( $short );
check( 'a short description cannot bring a marker of its own', array( substr_count( $short_sent['html'], 'data-tcg=' ), $region( $short_sent['html'] ) ), array( 1, '<p>The real one.</p>' ) );
TCGiant_Sync_Templates::save_block( 'planted', 'Planted', array( 'en' => '<div data-tcg="content"><p>From a block.</p></div>' ) );
update_post_meta( 96, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '{{{block.planted}}}{{{description}}}' ) ) );
$block_sent = described( $short );
check( '  nor a block', array( substr_count( $block_sent['html'], 'data-tcg=' ), $region( $block_sent['html'] ) ), array( 1, '<p>The real one.</p>' ) );

// A template's own markup cannot plant a marker (security, round 2).
update_post_meta( 96, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<div data-tcg="content"><p>Buy more at our shop.</p></div><div DATA-TCG=\'content\'>Again</div>{{{description}}}' ) ) );
$body_sent = described( $short );
// Nor one a placeholder makes: an empty one splitting the word, a section,
// or a value that brings it (security, round 3).
foreach ( array(
	'split by an empty placeholder' => array( '<div data-t{{nothing_here}}cg="content"><p>Planted.</p></div>{{{description}}}', 'Box' ),
	'split by a section'            => array( '<div data-{{#show.header}}{{/show.header}}tcg="content"><p>Planted.</p></div>{{{description}}}', 'Box' ),
	'from the title'                => array( '<div {{title}}><p>Planted.</p></div>{{{description}}}', 'data-tcg=content' ),
	'a slash for a space'           => array( '<div/data-tcg="content"><p>Planted.</p></div>{{{description}}}', 'Box' ),
) as $how => $planted_case ) {
	$short->data['name'] = $planted_case[1];
	update_post_meta( 96, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => $planted_case[0] ) ) );
	$case_sent = described( $short );
	check( 'a marker planted ' . $how . ': still one, the real content read back', array( substr_count( strtolower( $case_sent['html'] ), 'data-tcg' ), $region( $case_sent['html'] ) ), array( 1, '<p>The real one.</p>' ) );
}
$short->data['name'] = 'Box';
update_post_meta( 96, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<div>{{{description}}}</div><div>{{{description}}}</div>' ) ) );
$twice = described( $short );
check( 'a template that shows the description twice: one marker, on the first; the second keeps its class', array( substr_count( $twice['html'], 'data-tcg=' ), substr_count( $twice['html'], 'class="tcg-desc"' ), false === strpos( $twice['html'], 'tcgwrap' ) ), array( 1, 2, true ) );
update_post_meta( 96, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<div data-tcg="content"><p>Buy more at our shop.</p></div><div DATA-TCG=\'content\'>Again</div>{{{description}}}' ) ) );
$body_sent = described( $short );
check( 'a template\'s own data-tcg is taken out: one marker, the real content read back', array( substr_count( strtolower( $body_sent['html'] ), 'data-tcg' ), $region( $body_sent['html'] ) ), array( 1, '<p>The real one.</p>' ) );

// What the send records, for a re-import to know it back (push-path M3).
check( 'a templated send carries the hash of its region as an import reads it', $sent['region_hash'], sha1( $region( $sent['html'] ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWITHOUT IT: EXACTLY AS BEFORE\n" . str_repeat( '=', 124 ) . "\n";

$plain = "<p>A card.</p>\n<div><div>Nested.</div></div>";
check( 'a description with no marker imports byte for byte', $imported( $plain ), $plain );

$wplister = '<div id="wplister_template"><meta name="viewport" content="width=device-width"><style type="text/css">#wplister_template .desc { padding: 10px }</style>'
	. '<div class="header"><img src="https://shop.example/logo.png"></div><div class="description"><p>Holo rare, light play.</p></div>'
	. '<div class="section"><h3>Shipping</h3><p>replace this text with your shipping information</p></div></div>';
check( 'a WP-Lister description: no false positive, imported as it is', $imported( $wplister ), $wplister );

$unclosed = '<div class="tcg-desc" data-tcg="content"><p>Never closed.';
check( 'a marker never closed: left as it is', $region( $unclosed ), $unclosed );
check( 'the word "tcg-desc" in text is no marker', $region( '<p>See the tcg-desc class.</p>' ), '<p>See the tcg-desc class.</p>' );
check( 'nothing at all: nothing', $region( '' ), '' );

// The WP-Lister product pushed with a TCGiant template.
$w = new WC_Product( 92, array( 'name' => 'Imported', 'description' => $imported( $wplister ) ) );
update_post_meta( 92, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
$r = described( $w );
check( 'pushing it with a template raises the nested-template lint', count( array_filter( $r['warnings'], function ( $x ) { return false !== strpos( $x, 'already holds a <style> block' ); } ) ), 1 );
check( '  and its stylesheet does not reach eBay as words', false === strpos( $r['html'], 'padding: 10px' ), true );

done();
