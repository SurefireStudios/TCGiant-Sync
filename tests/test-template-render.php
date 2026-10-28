<?php
/**
 * Harness: what a template's placeholders put into the description.
 *
 * Logic-less and one pass: {{x}} is escaped, {{{x}}} is raw only for what the
 * pipeline has cleaned, sections and loops show or repeat, and a placeholder
 * nobody knows renders empty and says so. The values are what the item itself
 * sends - the specifics table is the ItemSpecifics after eBay's cut to thirty,
 * the options table the VariationSpecificsSet - so a description never
 * disagrees with the listing it is in.
 *
 * Loaded for real: the renderer, the templates, the sanitiser, kses, and the
 * exporter that gathers the values.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

$R = function ( $template, array $context, array $options = array() ) {
	return TCGiant_Sync_Template_Renderer::render( $template, $context, $options );
};

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE ENGINE\n" . str_repeat( '=', 124 ) . "\n";

$out = $R( '<b>{{title}}</b> {{{description}}}', array( 'title' => 'Tom & "Jerry" <script>', 'description' => '<p>ok</p>' ), array( 'raw' => array( 'description' ) ) );
check( '{{x}} is escaped; {{{x}}} is not, for a value the pipeline cleans', $out['html'], '<b>Tom &amp; &quot;Jerry&quot; &lt;script&gt;</b> <p>ok</p>' );

$out = $R( '{{{title}}}', array( 'title' => '<i>x</i>' ), array( 'raw' => array( 'description' ) ) );
check( '{{{x}}} on anything else is escaped all the same', $out['html'], '&lt;i&gt;x&lt;/i&gt;' );
check( '  and says so', 1 === count( $out['warnings'] ) && false !== strpos( $out['warnings'][0], 'always escaped' ), true );
$out = $R( '{{{block.note}}}|{{{blocky}}}|{{{title}}}', array( 'block.note' => '<b>n</b>', 'blocky' => '<b>y</b>', 'title' => '<i>t</i>' ), array( 'raw' => array( 'block.*' ) ) );
check( '"block.*" lets every block through raw, and nothing else', $out['html'], '<b>n</b>|&lt;b&gt;y&lt;/b&gt;|&lt;i&gt;t&lt;/i&gt;' );

$out = $R( 'Pok&eacute;mon {{t}}', array( 't' => 'Pok&eacute;mon &amp; co' ) );
check( 'an entity already in a value stays one, as esc_html() leaves it', $out['html'], 'Pok&eacute;mon Pok&eacute;mon &amp; co' );

$out = $R( 'A{{#on}}[yes {{v}}]{{/on}}{{^on}}[no]{{/on}}B', array( 'on' => true, 'v' => 1 ) );
check( 'a section shows when its value is set', $out['html'], 'A[yes 1]B' );
$out = $R( 'A{{#on}}[yes]{{/on}}{{^on}}[no]{{/on}}B', array( 'on' => '' ) );
check( '  and the inverted one when it is empty', $out['html'], 'A[no]B' );
$out = $R( '{{#g}}{{grade}}{{/g}}', array( 'g' => '0', 'grade' => '0' ) );
check( '  a "0" is a value, not empty', $out['html'], '0' );

$out = $R( '<ul>{{#rows}}<li>{{name}}={{value}}</li>{{/rows}}</ul>', array( 'rows' => array( array( 'name' => 'A', 'value' => '1' ), array( 'name' => 'B&C', 'value' => '2' ) ), 'name' => 'outer' ) );
check( 'a list repeats, each row its own context', $out['html'], '<ul><li>A=1</li><li>B&amp;C=2</li></ul>' );

$out = $R( '{{#grading}}{{grader}} {{grade}}{{#cert}} cert {{cert}}{{/cert}}{{/grading}}', array( 'grading' => array( 'grader' => 'PSA', 'grade' => '10', 'cert' => '' ) ) );
check( 'a section with a row: its values, and an empty one inside left out', $out['html'], 'PSA 10' );

$out = $R( 'x{{nobody}}y', array() );
check( 'an unknown placeholder renders empty', $out['html'], 'xy' );
check( '  and warns', $out['warnings'], array( 'Unknown placeholder {{nobody}}: it rendered empty.' ) );

$out = $R( '{{title}} and {{sku}}', array( 'title' => 'Card {{sku}} {{{description}}}', 'sku' => 'S1' ) );
check( 'one pass: a title holding {{sku}} reaches eBay as those characters', $out['html'], 'Card {{sku}} {{{description}}} and S1' );

$out = $R( '{{#open}}never closed', array( 'open' => true ) );
check( 'a section never closed runs to the end, with a warning', array( $out['html'], count( $out['warnings'] ) ), array( 'never closed', 1 ) );
$out = $R( 'a{{/stray}}b', array() );
check( 'a stray close is left out, with a warning', array( $out['html'], count( $out['warnings'] ) ), array( 'ab', 1 ) );
$out = $R( '{{ not a name }} {{a b}} {{', array() );
check( 'what is not a well-formed placeholder is text', $out['html'], '{{ not a name }} {{a b}} {{' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE VALUES, FROM WHAT THE ITEM SENDS\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['W']['term_names']['pa_set|31'] = 'Base Set';
$GLOBALS['W']['term_names']['pa_brand|7'] = 'Wizards of the Coast';
$GLOBALS['W']['images'] = array( 501 => 'https://shop.example/a.jpg', 502 => 'http://shop.example/b.jpg', 503 => 'https://cdn.example/c.jpg' );

$attrs = array(
	new WC_Product_Attribute( 'pa_set', array( 31 ), true ),
	new WC_Product_Attribute( 'pa_brand', array( 7 ), true ),
);
for ( $i = 1; $i <= 33; $i++ ) {
	$attrs[] = new WC_Product_Attribute( 'Spec ' . $i, array( 'value ' . $i ) );
}
$card = new WC_Product( 21, array( 'name' => 'Charizard 4/102 {{sku}}', 'description' => 'Holo.', 'sku' => 'CHZ-4', 'attributes' => $attrs, 'image_id' => 501, 'gallery' => array( 502, 503 ) ) );
update_post_meta( 21, '_cost_price', '3.10' );
update_post_meta( 21, 'card_year', '1999' );
update_post_meta( 21, 'stock_note', 'in the back' );

$tpl = make_template( array( 'html' => '<h1>{{title}}</h1><p>{{sku}} {{attr.pa_set}} / {{attr.brand}}</p><p>[{{meta._cost_price}}][{{meta.card_year}}][{{meta.stock_note}}]</p>{{{specifics_table}}}<p>{{main_image}}|{{image.2}}|{{image.3}}|{{image.24}}</p>{{#images}}<i>{{url}}</i>{{/images}}' ) );
update_post_meta( 21, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'meta_allowlist' => array( 'card_year' ) ) );
$sent = described( $card );
$html = $sent['html'];

check( '{{title}} is the title the item sends, a "{{sku}}" in it kept as text', false !== strpos( $html, '<h1>Charizard 4/102 {{sku}}</h1>' ), true );

// A title is never written raw: in an attribute it could add attributes.
$quoted = new WC_Product( 24, array( 'name' => 'Ash" class="evil', 'description' => 'x' ) );
update_post_meta( 24, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<p title="{{{title}}}">x</p>' ) ) );
$q = described( $quoted );
check( '{{{title}}} is escaped all the same: a quote in the title adds no attribute', 0 === strpos( $q['html'], '<p title=\'Ash" class="evil\'>x</p>' ), true );
check( '{{attr.x}} gives term names, by slug and without its pa_', false !== strpos( $html, 'CHZ-4 Base Set / Wizards of the Coast' ), true );
check( '{{meta.x}}: only what is allowlisted - a "_" key and a plain one refused', false !== strpos( $html, '[][1999][]' ), true );
check( '  each refusal says so', count( array_filter( $sent['warnings'], function ( $w ) { return false !== strpos( $w, 'not on the allowlist' ); } ) ), 2 );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'meta_allowlist' => array( 'card_year', '_cost_price' ) ) );
check( '  a "_" key allowlisted on purpose is given', false !== strpos( described( $card )['html'], '[3.10][1999][]' ), true );

check( 'images: the site\'s own, https, http moved to https on an https site; {{image.N}} past the last is empty', false !== strpos( $html, '<p>https://shop.example/a.jpg|https://shop.example/b.jpg|https://cdn.example/c.jpg|</p>' ), true );
check( '  and {{#images}} lists the same three', substr_count( $html, '<i>https://' ), 3 );

// The specifics table against the item's own ItemSpecifics.
preg_match( '#<ItemSpecifics>(.*)</ItemSpecifics>#s', item_xml( $card, push_settings( $card ) ), $is );
preg_match_all( '#<Name>(.*?)</Name>\s*<Value>(.*?)</Value>#s', $is[1], $xml_pairs, PREG_SET_ORDER );
preg_match_all( '#<th[^>]*>(.*?)</th><td[^>]*>(.*?)</td>#s', $html, $table_pairs, PREG_SET_ORDER );
$decode = function ( $rows ) {
	return array_map( function ( $r ) {
		return array( html_entity_decode( $r[1], ENT_QUOTES | ENT_XML1 ), html_entity_decode( $r[2], ENT_QUOTES | ENT_XML1 ) );
	}, $rows );
};
check( 'the item sends 30 of the 35 specifics', count( $xml_pairs ), 30 );
check( 'the specifics table holds exactly those 30, in order', $decode( $table_pairs ), $decode( $xml_pairs ) );

// A variable product's options against its VariationSpecificsSet.
$GLOBALS['W']['term_names']['pa_size|small'] = 'Small';
$GLOBALS['W']['term_names']['pa_size|large'] = 'Large';
$var = new WC_Product_Variable( 22, array( 'name' => 'Sleeves', 'description' => 'Box of 100.', 'type' => 'variable', 'regular_price' => '9.99' ) );
$var->variation_attributes = array( 'pa_size' => array( 'small', 'large' ), 'Colour' => array( 'Red', 'Blue' ) );
update_post_meta( 22, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '{{{variations_table}}}' ) ) );
$vhtml = described( $var )['html'];
preg_match( '#<VariationSpecificsSet>(.*)</VariationSpecificsSet>#s', item_xml( $var, push_settings( $var ) ), $vs );
preg_match_all( '#<NameValueList>\s*<Name>(.*?)</Name>(.*?)</NameValueList>#s', $vs[1], $sets, PREG_SET_ORDER );
$xml_sets = array();
foreach ( $sets as $set ) {
	preg_match_all( '#<Value>(.*?)</Value>#', $set[2], $values );
	$xml_sets[] = array( $set[1], implode( ', ', $values[1] ) );
}
preg_match_all( '#<th[^>]*>(.*?)</th><td[^>]*>(.*?)</td>#s', $vhtml, $vrows, PREG_SET_ORDER );
check( 'the options table lists what VariationSpecificsSet sends', $decode( $vrows ), $decode( array_map( function ( $s ) { return array( 0, $s[0], $s[1] ); }, $xml_sets ) ) );
check( '  two attributes, two rows', count( $vrows ), 2 );

// A global "Color" and a custom "Color": two axes, as 3.27.7 sent them
// (push-path L1), in the item and in the table.
$GLOBALS['W']['term_names']['pa_color|red'] = 'Red';
$axes = new WC_Product_Variable( 25, array( 'name' => 'Sleeves', 'description' => 'x', 'type' => 'variable', 'regular_price' => '1' ) );
$axes->variation_attributes = array( 'pa_color' => array( 'red' ), 'Color' => array( 'Blue' ) );
update_post_meta( 25, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '{{{variations_table}}}' ) ) );
preg_match( '#<VariationSpecificsSet>(.*)</VariationSpecificsSet>#s', item_xml( $axes, push_settings( $axes ) ), $axes_vs );
preg_match_all( '#<th[^>]*>(.*?)</th><td[^>]*>(.*?)</td>#s', described( $axes )['html'], $axes_rows, PREG_SET_ORDER );
check( 'two attributes with the same label: two NameValueLists, as 3.27.7 sent', substr_count( $axes_vs[1] ?? '', '<NameValueList>' ), 2 );
check( '  and two rows in the options table', array_map( function ( $r ) { return $r[1] . '=' . $r[2]; }, $axes_rows ), array( 'Color=Red', 'Color=Blue' ) );
check( '  and no price', false === strpos( $vhtml, '9.99' ), true );

// The condition and the grading line, as the descriptors send them.
$graded = new WC_Product( 23, array( 'name' => 'Slab', 'description' => 'x' ) );
foreach ( array( '_ebay_export_condition_type' => 'graded', '_ebay_export_grader_id' => '275010', '_ebay_export_grade_value' => '10', '_ebay_export_cert_number' => '95422421' ) as $k => $v ) {
	update_post_meta( 23, $k, $v );
}
$g_tpl = make_template( array( 'html' => '[{{condition_name}}]{{#grading}}[{{grader}}|{{grade}}|{{cert}}]{{/grading}}{{^grading}}[ungraded]{{/grading}}' ) );
update_post_meta( 23, TCGiant_Sync_Templates::OVERRIDE_META, (string) $g_tpl );
check( 'a graded card: "Graded", and its grader, grade and cert', false !== strpos( described( $graded )['html'], '[Graded][Professional Sports Authenticator (PSA)|10|95422421]' ), true );
update_post_meta( 23, '_ebay_export_condition_type', 'ungraded' );
update_post_meta( 23, '_ebay_export_ungraded_condition', '400010' );
check( 'ungraded: its condition named, and no grading line', false !== strpos( described( $graded )['html'], '[Ungraded - Near Mint or Better][ungraded]' ), true );

done();
