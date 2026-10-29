<?php
/**
 * Harness: what a templated or eBay-only description may carry to eBay.
 *
 * eBay publishes no list of allowed tags, and a description with active
 * content fails with "Disallowed JavaScript/HTML Syntax". The subset is ours.
 * What matters most is what kses alone gets wrong: it strips a tag it does not
 * allow but keeps the text inside, so the code of a <script>, or the
 * stylesheet of an imported WP-Lister description, would reach eBay as words
 * a buyer reads - and it turns ">" into "&gt;", which would break a child
 * selector if the stylesheet went through it.
 *
 * Loaded for real: the sanitiser, the templates, the exporter's push up to its
 * eBay call, and WordPress's own kses (tests/wordpress/kses.php), so inline
 * styles go through WordPress's safecss_filter_attr().
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

$args  = array( 'home_host' => 'shop.example', 'site_https' => true );
$clean = function ( $html, $marker = false ) use ( $args ) {
	return TCGiant_Sync_Template_Sanitiser::clean( $html, $args + array( 'marker' => $marker ) );
};

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHOLE ELEMENTS GO, WITH WHAT IS INSIDE THEM\n" . str_repeat( '=', 124 ) . "\n";

$out = $clean( '<p>Hi</p><script>document.cookie="x"</script><style>.a{color:red}</style><p>after</p>' );
check( 'a <script> and a <style> leave no text behind', $out['html'], '<p>Hi</p><p>after</p>' );
check( '  and are listed as removed', $out['removed'], array( 'script', 'style' ) );

$nasty = '<iframe src="https://evil.example">frame text</iframe><form action="x">form text<input name="q"><select><option>opt</option></select><textarea>ta</textarea></form>'
	. '<meta http-equiv="refresh" content="0;url=https://evil.example"><base href="https://evil.example/"><object>obj</object><embed src="x"><svg><script>s</script></svg>'
	. '<noscript>ns</noscript><template>tp</template><title>tt</title><link rel="stylesheet" href="https://x"><video>v</video><audio>a</audio><canvas>c</canvas><math>m</math><p>kept</p>';
$out = $clean( $nasty );
check( 'iframe, form, meta, base, object, embed, svg and the rest go whole', $out['html'], '<p>kept</p>' );

$out = $clean( '<p onclick="alert(1)" onmouseover="x()" style="color:red">a</p><a href="javascript:alert(1)">js</a><a href="vbscript:x">vb</a><img src="data:image/png;base64,AAAA"><img src="https://shop.example/ok.jpg" onerror="x()">' );
check( 'on* attributes go', false === stripos( $out['html'], 'onclick' ) && false === stripos( $out['html'], 'onerror' ) && false === stripos( $out['html'], 'onmouseover' ), true );
check( 'javascript: and vbscript: links become their text', false === stripos( $out['html'], 'script:' ) && false !== strpos( $out['html'], 'js' ) && false !== strpos( $out['html'], 'vb' ), true );
check( 'a data: image goes', false === stripos( $out['html'], 'data:' ) && false === strpos( $out['html'], 'base64' ), true );
check( 'inline style is kept as WordPress filters it', false !== strpos( $out['html'], '<p style="color:red">a</p>' ), true );

$out = $clean( '<p>one</p></div></body><p>two</p></html><p>three</p>' );
check( 'a stray </body> loses nothing after it', $out['html'], '<p>one</p><p>two</p><p>three</p>' );

$out = $clean( '<p>x</p><!-- <script>bad()</script> --><!--[if IE]><b>ie</b><![endif]-->' );
check( 'HTML comments go, conditional ones too', $out['html'], '<p>x</p>' );

$out = $clean( '<font color="red">red</font> <center>mid</center> <blink>b</blink>' );
check( 'a harmless wrapper loses its tag and keeps its text', $out['html'], 'red mid b' );

$out = $clean( '<div data-tcg="content" data-x="1">x</div>' );
check( 'data-* attributes go from content', $out['html'], '<div>x</div>' );
$out = $clean( '<div class="tcg-desc" data-tcg="content">x</div>', true );
check( '  the content marker stays on the last pass, which allows it', $out['html'], '<div class="tcg-desc" data-tcg="content">x</div>' );

// Without PHP's DOM extension the same list is removed by pattern.
$by_pattern = private_method( 'TCGiant_Sync_Template_Sanitiser', 'remove_elements_by_pattern' );
$removed    = array();
$html       = $by_pattern->invokeArgs( null, array( '<p>Hi</p><SCRIPT type="x">alert(1)</SCRIPT><style>.a{}</style><iframe src=x></iframe><!-- c --><p>after</p>', &$removed ) );
check( 'without DOM: the same elements go, by pattern, text and all', $html, '<p>Hi</p><p>after</p>' );
$links_by_pattern = private_method( 'TCGiant_Sync_Template_Sanitiser', 'links_and_images_by_pattern' );
$warned           = array();
$html             = $links_by_pattern->invokeArgs( null, array( '<p><a href="https://evil.example/">never closed</p><p><a href="https://www.ebay.com/str/x">ours</a></p><div style="background:url(http://evil.example/b.png)">bg</div>', $args, &$removed, &$warned ) );
check( '  a link never closed goes too (security review, 4); eBay\'s stays', array( false === strpos( $html, 'evil.example/"' ), false !== strpos( $html, '<a href="https://www.ebay.com/str/x">ours</a>' ), false !== strpos( $html, 'never closed' ) ), array( true, true, true ) );
check( '  and an inline style\'s http picture', false === strpos( $html, 'evil.example/b.png' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE STYLESHEET, WHICH NEVER GOES THROUGH KSES\n" . str_repeat( '=', 124 ) . "\n";

$css = TCGiant_Sync_Template_Sanitiser::filter_css( ".box > p { color: #333 } ul li + li { margin: 0 }\n@import url(https://fonts.example/a.css);\n.x { width: expression(alert(1)); color: blue }\n.y { background: url(http://cdn.example/bg.png) no-repeat }\n.z { background-image: url('https://shop.example/ok.png') }\n.b { behavior: url(x.htc) } .m { -moz-binding: url(x) } .j { background: url(javascript:alert(1)) }" );
check( 'a child selector (a > b) survives intact', false !== strpos( $css['css'], '.box > p { color: #333 }' ) && false !== strpos( $css['css'], 'ul li + li' ), true );
check( '@import goes', false === stripos( $css['css'], '@import' ), true );
check( 'expression( goes, the rest of its rule kept', false === stripos( $css['css'], 'expression' ) && false !== strpos( $css['css'], 'color: blue' ), true );
check( 'an http url() goes; an https one stays', false === strpos( $css['css'], 'http://' ) && false !== strpos( $css['css'], 'url("https://shop.example/ok.png")' ), true );
check( 'behavior, -moz-binding and javascript: go', false === stripos( $css['css'], 'behavior' ) && false === stripos( $css['css'], 'moz-binding' ) && false === stripos( $css['css'], 'javascript' ), true );
$css = TCGiant_Sync_Template_Sanitiser::filter_css( 'p{color:red}</style><script>alert(1)</script>' );
check( 'nothing in it can close its <style>', false === strpos( $css['css'], '<' ), true );

// Escapes a browser reads as letters, and addresses in strings (security review, 2).
$B   = chr( 92 );
$css = TCGiant_Sync_Template_Sanitiser::filter_css( '@' . $B . '69mport url("https://evil.example/x.css"); @im' . $B . 'port "https://evil.example/y.css"; .a{color:red}' );
check( 'an escaped @import goes, in either form', array( false === stripos( $css['css'], 'import' ), false === strpos( $css['css'], 'evil.example' ), false !== strpos( $css['css'], '.a{color:red}' ) ), array( true, true, true ) );
$css = TCGiant_Sync_Template_Sanitiser::filter_css( '.x{background:' . $B . '75rl(http://evil.example/t.png)} .y{width:ex' . $B . 'pression(alert(1))} .z{be' . $B . 'havior:url(https://evil.example/b.htc)} .w{-moz-b' . $B . 'inding:url(https://evil.example/m.xml)} .v{background:url(java' . $B . 'script:alert(1))}' );
check( 'an escaped url(, expression(, behavior, -moz-binding and javascript: go', array( false === strpos( $css['css'], 'evil.example' ), false === stripos( $css['css'], 'expression' ), false === stripos( $css['css'], 'javascript' ) ), array( true, true, true ) );
$css = TCGiant_Sync_Template_Sanitiser::filter_css( '.i{background-image:image-set("http://evil.example/i.png" 1x)} .k{background-image:-webkit-image-set("http://evil.example/k.png" 1x)} .ok{background-image:image-set("https://cdn.example/ok.png" 1x)}' );
check( 'image-set() with an http address goes, -webkit- too; an https one stays', array( false === strpos( $css['css'], 'evil.example' ), false !== strpos( $css['css'], 'https://cdn.example/ok.png' ) ), array( true, true ) );
$css = TCGiant_Sync_Template_Sanitiser::filter_css( '.a{color:red} .b{background:url(http://evil.example/u.png' );
check( 'a url( never closed takes the rest of the stylesheet with it', array( false === strpos( $css['css'], 'evil.example' ), false !== strpos( $css['css'], '.a{color:red}' ) ), array( true, true ) );
// An escaped backslash is one pair, never taken apart into a new escape (security, round 2).
$css = TCGiant_Sync_Template_Sanitiser::filter_css( '@' . $B . $B . '69mport url("https://evil.example/x.css"); .a{color:red}' );
check( '@ and an escaped backslash before 69mport stay so: never @' . $B . 'import, which a browser reads as @import', array( false !== strpos( $css['css'], '@' . $B . $B . '69mport' ), false === strpos( str_replace( $B . $B, '', $css['css'] ), $B . 'import' ) ), array( true, true ) );
foreach ( array(
	'url('     => '.x{background-image:u' . $B . $B . '72l(http://evil.example/t.png)}',
	'unclosed' => '.x{background-image:u' . $B . $B . '72l(http://evil.example/u.png',
	'expression' => 'p{width:ex' . $B . $B . '70ression(alert(1))}',
) as $what => $in ) {
	$out = TCGiant_Sync_Template_Sanitiser::filter_css( $in )['css'];
	check( '  nor an escaped backslash before an escape made into ' . $what, array( false === strpos( $out, 'u' . $B . 'rl(' ), false === stripos( $out, 'url(' ), false === strpos( $out, 'ex' . $B . 'pression' ) ), array( true, true, true ) );
}
foreach ( array( ';' => '.y{background-image:image-set("https://ok.example/a.png;" 1x, "http://evil.example/i.png" 2x)}', '}' => '.y{background-image:image-set("https://ok.example/}" 1x, "http://evil.example/j.png" 2x)}' ) as $char => $in ) {
	check( 'image-set() with a ' . $char . ' inside a string still has its http address found', false === strpos( TCGiant_Sync_Template_Sanitiser::filter_css( $in )['css'], 'evil.example' ), true );
}
$css = TCGiant_Sync_Template_Sanitiser::filter_css( 'li:before { content: "' . $B . '2022" } .q:after { content: "' . $B . '"" }' );
check( 'an escape that stands for anything else is left as it is', $css['css'], 'li:before { content: "' . $B . '2022" } .q:after { content: "' . $B . '"" }' );

// Through a whole render: the stylesheet goes in after kses, untouched by it.
$p   = new WC_Product( 31, array( 'name' => 'Card', 'description' => 'Plain.' ) );
$tpl = make_template( array( 'html' => '<div class="box"><p>{{{description}}}</p></div>', 'css' => '.box > p { color: {{design.accent}} } .box > p > .tcg-desc { margin: 0 }' ) );
update_post_meta( 31, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );
$html = described( $p )['html'];
check( 'in a rendered description, the child selectors reach eBay as ">"', false !== strpos( $html, '<style>.box > p { color: #1f4e79 } .box > p > .tcg-desc { margin: 0 }</style>' ), true );
check( '  as one <style>, at the top', 0 === strpos( $html, '<style>' ) && 1 === substr_count( $html, '<style' ), true );
update_post_meta( 31, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array(
	'html' => '<p>{{{description}}}</p>',
	'css'  => '@import url(https://fonts.example/a.css); p { color: red; width: expression(alert(1)) } .b { background: url(http://cdn.example/x.png) } .c{}</style><script>alert(1)</script>',
) ) );
$html = described( $p )['html'];
check( '  through the stylesheet filter: no @import, expression(, http url() or way out of its <style>', array( false === stripos( $html, '@import' ), false === stripos( $html, 'expression' ), false === strpos( $html, 'http://cdn.example' ), false === stripos( $html, '<script' ), 1 === substr_count( $html, '</style>' ), false !== strpos( $html, 'color: red' ) ), array( true, true, true, true, true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nLINKS AND IMAGES\n" . str_repeat( '=', 124 ) . "\n";

$out = $clean( '<a href="http://www.ebay.com/str/cardboardshop">our Store</a> <a href="https://stores.ebay.co.uk/x">uk</a> <a href="https://ebay.com.evil.example/x">fake</a> <a href="https://www.example.com">site</a> <a href="mailto:me@example.com">mail</a> <a href="/relative">rel</a>' );
check( 'an eBay link is forced to https', false !== strpos( $out['html'], '<a href="https://www.ebay.com/str/cardboardshop">our Store</a>' ), true );
check( '  another eBay site\'s link is kept too', false !== strpos( $out['html'], '<a href="https://stores.ebay.co.uk/x">uk</a>' ), true );
check( 'a link off eBay becomes its text - a look-alike host included', substr_count( $out['html'], '<a ' ), 2 );
check( '  its text kept', false !== strpos( $out['html'], 'fake site mail rel' ), true );

// parse_url() and a browser disagree about these; none may pass as eBay's
// (security review, 1). A browser reads "\" as "/" and drops tabs.
$B       = chr( 92 );
$hostile = array(
	'https://evil.example' . $B . '@www.ebay.com/',
	'https://evil.example' . $B . '.ebay.com/',
	'https://evil.example%5C@www.ebay.com/',
	'https://www.ebay.com@evil.example/',
	'https://evil.example#@www.ebay.com',
	'https://evil.example?@www.ebay.com',
	"https://evil.example\t@www.ebay.com/",
	'https:' . $B . $B . 'evil.example' . $B . '@www.ebay.com',
	'//evil.example/@www.ebay.com',
	'https://evil.example:443' . $B . '@www.ebay.com/',
);
$passed = array();
foreach ( $hostile as $href ) {
	$kept = $clean( '<p><a href="' . htmlspecialchars( $href ) . '">x</a></p>' )['html'];
	if ( '' !== TCGiant_Sync_Template_Sanitiser::ebay_link( $href ) || false !== strpos( $kept, '<a ' ) ) {
		$passed[] = $href;
	}
}
check( 'none of the ten addresses parse_url() and a browser read differently passes as eBay\'s', $passed, array() );
check( '  while eBay\'s own, in capitals or with a port, are kept', array( TCGiant_Sync_Template_Sanitiser::ebay_link( 'HTTPS://www.ebay.com/itm/1' ), TCGiant_Sync_Template_Sanitiser::ebay_link( 'https://www.ebay.com:80/' ) ), array( 'https://www.ebay.com/itm/1', 'https://www.ebay.com:80/' ) );
check( '  a backslash, a space or a control character anywhere refuses it, as does a host that is not plain letters, digits, dots and hyphens', array( TCGiant_Sync_Template_Sanitiser::ebay_link( 'https://www.ebay.com/a' . $B . 'b' ), TCGiant_Sync_Template_Sanitiser::ebay_link( 'https://www.ebay.com/a b' ), TCGiant_Sync_Template_Sanitiser::ebay_link( "https://www.ebay.com/\x01" ), TCGiant_Sync_Template_Sanitiser::ebay_link( 'https://evil.example%2f.ebay.com/' ) ), array( '', '', '', '' ) );
check( '  and an eBay Store address with a backslash is not one', TCGiant_Sync_Templates::sanitize_settings( array( 'ebay_store_url' => 'https://evil.example' . $B . '@www.ebay.com/str/x' ) )['ebay_store_url'], '' );

// A background picture in an inline style follows the rule for images
// (security review, 5): kses lets any http(s) address through there.
$out = $clean( '<div style="background-image:url(http://evil.example/x.png)">a</div><div style="background:url(https://cdn.example/y.png)">b</div><div style="background:url(http://shop.example/z.png)">c</div>' );
check( 'an inline style\'s http picture goes, an https one stays, the shop\'s own moves to https', array( false === strpos( $out['html'], 'evil.example' ), false !== strpos( $out['html'], 'url(https://cdn.example/y.png)' ), false !== strpos( $out['html'], 'url(https://shop.example/z.png)' ) ), array( true, true, true ) );
check( '  and the one that went is said', 1 === count( array_filter( $out['warnings'], function ( $w ) { return false !== strpos( $w, 'http://evil.example/x.png' ); } ) ), true );

$out = $clean( '<img src="http://shop.example/wp-content/uploads/c.jpg" alt="c"><img src="http://other.example/d.jpg"><img src="https://cdn.example/e.jpg" style="border:1px solid #ccc">' );
check( 'the shop\'s own http image is moved to https on an https site', false !== strpos( $out['html'], 'src="https://shop.example/wp-content/uploads/c.jpg"' ), true );
check( '  another site\'s http image is dropped', false === strpos( $out['html'], 'other.example' ), true );
check( '  and that is said', 1 === count( $out['warnings'] ) && false !== strpos( $out['warnings'][0], 'http://other.example/d.jpg' ), true );
check( 'each image is given max-width:100%;height:auto, once', array( substr_count( $out['html'], 'max-width:100%;height:auto' ), false !== strpos( $out['html'], 'border:1px solid #ccc;max-width:100%;height:auto' ) ), array( 2, true ) );
$out = TCGiant_Sync_Template_Sanitiser::clean( '<img src="http://shop.example/c.jpg">', array( 'home_host' => 'shop.example', 'site_https' => false ) );
check( 'on a site that is not https, even its own http image is dropped', $out['html'], '' );

check( '  in an attribute too, however long', 1 === count( array_filter( TCGiant_Sync_Template_Sanitiser::lint( '<img src="https://shop.example/a.png" alt="[[attribute_' . str_repeat( 'Long label ', 12 ) . ']]">' ), function ( $w ) { return false !== strpos( $w, 'placeholder' ); } ) ), true );
check( 'the lint says when a [[placeholder]] from another plugin would reach buyers', 1 === count( array_filter( TCGiant_Sync_Template_Sanitiser::lint( '<p>Card [[attribute_Card #]]</p>' ), function ( $w ) { return false !== strpos( $w, '[[attribute_Card #]]' ); } ) ), true );

$script = function ( $html ) {
	return array_values( array_filter( TCGiant_Sync_Template_Sanitiser::lint( $html ), function ( $w ) { return false !== strpos( $w, 'active-content' ); } ) );
};
check( 'the lint names ".cookie", which eBay refused live', $script( '<p>x.cookie</p>' ), array( 'The text ".cookie" looks like script, which eBay\'s active-content check refuses. Check with eBay to be sure.' ) );
check( '  and the call form of includes', count( $script( '<p>list.includes(x)</p>' ) ), 1 );
check( '  not "includes" as a plain word, which eBay accepted live', $script( '<p>This lot includes one graded card.</p>' ), array() );

$fixed = function ( $html ) {
	return count( array_filter( TCGiant_Sync_Template_Sanitiser::lint( $html ), function ( $w ) { return false !== strpos( $w, 'fixed width' ); } ) );
};
check( 'the lint flags a fixed width over 700 px', $fixed( '<div style="width:800px">x</div>' ), 1 );
check( '  not a max-width, nor a media query\'s (width: 768px)', $fixed( '<style>@media(width: 768px){p{}} @media (max-width: 900px){}</style><div style="max-width:900px;min-width:720px">x</div>' ), 0 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE EBAY-ONLY DESCRIPTION, TEMPLATE OR NOT\n" . str_repeat( '=', 124 ) . "\n";

$only = new WC_Product( 32, array( 'name' => 'Coin', 'description' => 'Website text.' ) );
update_post_meta( 32, TCGiant_Sync_Templates::EBAY_DESCRIPTION_META, '<p>For eBay.</p><script>steal()</script><iframe src="x">f</iframe>' );
$sent = described( $only );
check( 'on the None path it is the content, cleaned', $sent['html'], '<p>For eBay.</p>' );
check( '  sent as None, with what went listed', array( $sent['none'], $sent['removed'] ), array( true, array( 'script', 'iframe' ) ) );
update_post_meta( 32, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<section>{{{description}}}</section>' ) ) );
check( 'with a template, it is the content region', false !== strpos( described( $only )['html'], '<section><div class="tcg-desc" data-tcg="content"><p>For eBay.</p></div></section>' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SIZE GATE, BEFORE ANY EBAY CALL\n" . str_repeat( '=', 124 ) . "\n";

$do_push = private_method( 'TCGiant_Sync_Exporter', 'do_push' );

// A category that needs no grading, so the push gets as far as its eBay call.
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';

// A template that adds only its marker and comment: what it adds, measured.
$wrap  = (string) make_template( array( 'html' => '{{{description}}}' ) );
$probe = new WC_Product( 38, array( 'name' => 'Probe', 'description' => 'bbbb' ) );
update_post_meta( 38, TCGiant_Sync_Templates::OVERRIDE_META, $wrap );
$overhead = described( $probe )['chars'] - 4;

$big = new WC_Product( 33, array( 'name' => 'Big', 'description' => str_repeat( 'a', 500001 - $overhead ), 'image_id' => 0 ) );
update_post_meta( 33, TCGiant_Sync_Templates::OVERRIDE_META, $wrap );
$GLOBALS['API']->calls = array();
$refused = $do_push->invoke( exporter(), $big );
check( 'a templated description of 500,001 characters is refused', is_wp_error( $refused ) ? $refused->get_error_code() : $refused, 'description_too_long' );
check( '  with the count shown', is_wp_error( $refused ) && false !== strpos( $refused->get_error_message(), '500,001' ), true );
check( '  and nothing asked of eBay', $GLOBALS['API']->calls, array() );

$exactly = new WC_Product( 35, array( 'name' => 'Edge', 'description' => str_repeat( 'b', 500000 - $overhead ) ) );
update_post_meta( 35, TCGiant_Sync_Templates::OVERRIDE_META, $wrap );
check( 'exactly 500,000 is allowed', is_array( $do_push->invoke( exporter(), $exactly ) ), true );

$german = new WC_Product( 34, array( 'name' => 'Größe', 'description' => str_repeat( 'Größenänderung ', 33000 ) ) );
update_post_meta( 34, TCGiant_Sync_Templates::OVERRIDE_META, $wrap );
$sent   = described( $german );
check( 'German text is counted in characters, not bytes', array( $sent['chars'], strlen( $sent['html'] ) > 500000 ), array( mb_strlen( $sent['html'], 'UTF-8' ), true ) );
$ok = $do_push->invoke( exporter(), $german );
check( '  so 495,000 characters (594,000 bytes) go to eBay', is_array( $ok ) ? $ok['action'] : $ok->get_error_message(), 'created' );

$only_big = new WC_Product( 40, array( 'name' => 'Only big', 'description' => 'x' ) );
update_post_meta( 40, TCGiant_Sync_Templates::EBAY_DESCRIPTION_META, str_repeat( 'c', 500001 ) );
$GLOBALS['API']->calls = array();
$refused = $do_push->invoke( exporter(), $only_big );
check( 'an eBay-only description over the limit is refused too, with nothing asked of eBay', array( is_wp_error( $refused ) ? $refused->get_error_code() : 'sent', $GLOBALS['API']->calls ), array( 'description_too_long', array() ) );

// With no template, the plain description goes as 3.27.7 sent it: eBay decides.
$plain_big = new WC_Product( 39, array( 'name' => 'Plain big', 'description' => str_repeat( 'a', 500001 ) ) );
$GLOBALS['API']->calls = array();
$sent = $do_push->invoke( exporter(), $plain_big );
check( 'no template: a 500,001-character plain description is sent, as 3.27.7 sent it', array( is_array( $sent ) ? $sent['action'] : $sent->get_error_code(), array_column( $GLOBALS['API']->calls, 0 ) ), array( 'created', array( 'AddFixedPriceItem' ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CDATA WRAP, UNCHANGED\n" . str_repeat( '=', 124 ) . "\n";

$code = new WC_Product( 36, array( 'name' => 'Code', 'description' => 'x' ) );
// kses writes a ">" in text as "&gt;", so a "]]>" reaches the item only
// through the stylesheet, which kses never sees.
update_post_meta( 36, TCGiant_Sync_Templates::OVERRIDE_META, (string) make_template( array( 'html' => '<p>{{{description}}}</p>', 'css' => 'p:after { content: "]]>" }' ) ) );
$xml = item_xml( $code, push_settings( $code ) );
check( 'a "]]>" in a template is still split at the CDATA wrap', false !== strpos( $xml, 'content: "]]]]><![CDATA[>" }' ), true );
preg_match( '#<Description>(.*?)</Description>#s', $xml, $d );
check( '  and the Description reads back as the description', isset( $d[1] ) ? (string) simplexml_load_string( '<D>' . $d[1] . '</D>' ) : null, described( $code )['html'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nCLEAN, WITH ITS <style> REMOVED, STILL READS AS ONE COLUMN\n" . str_repeat( '=', 124 ) . "\n";

$clean_tpl = TCGiant_Sync_Templates::save( 0, array_merge( TCGiant_Sync_Template_Starters::get( 'clean' ), array( 'status' => 'publish' ) ) );
$shop      = new WC_Product( 37, array( 'name' => 'Charizard', 'description' => '<p>Holo.</p>', 'attributes' => array( new WC_Product_Attribute( 'Set', array( 'Base Set' ) ) ) ) );
update_post_meta( 37, TCGiant_Sync_Templates::OVERRIDE_META, (string) $clean_tpl );
$sent  = described( $shop )['html'];
$bare  = TCGiant_Sync_Template_Sanitiser::clean( $sent, $args )['html'];
check( 'its <style> goes through the sanitiser as eBay might treat it: removed', false === strpos( $bare, '<style' ), true );
check( '  the page keeps its width and centring inline', false !== strpos( $bare, 'max-width:700px;margin:0 auto' ), true );
check( '  title, description and specifics in that order, nothing floated', strpos( $bare, 'Charizard</h1>' ) < strpos( $bare, 'Holo.' ) && strpos( $bare, 'Holo.' ) < strpos( $bare, 'Base Set' ) && false === strpos( $bare, 'float' ), true );

done();
