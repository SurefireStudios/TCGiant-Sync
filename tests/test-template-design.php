<?php
/**
 * Harness: the design-settings form on each template.
 *
 * A seller who never touches code picks an accent colour, the colour of text
 * on it, a page background, a logo, a banner, and which parts show. Each
 * reaches the template as {{design.x}} or {{#show.x}}, and nothing typed into
 * the form can reach the markup or the stylesheet as markup: a colour is
 * #rrggbb or it is the starter's own, an image is an attachment of this
 * site's served over https, and a part is on or off.
 *
 * Loaded for real: the templates (their save path and render), the renderer,
 * the sanitiser and WordPress's kses.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

$d = function ( $design ) {
	return TCGiant_Sync_Templates::sanitize_design( $design );
};

// ─────────────────────────────────────────────────────────────────────────────
echo "\nCOLOURS ARE #rrggbb OR NOTHING\n" . str_repeat( '=', 124 ) . "\n";

check( 'a colour as #rrggbb is kept, lower case', $d( array( 'accent' => '#1F4E79' ) )['accent'], '#1f4e79' );
check( '  with spaces round it, trimmed', $d( array( 'accent' => '  #abcdef ' ) )['accent'], '#abcdef' );
foreach ( array( '#FFF', 'red', '#12345g', '#1234567', '1f4e79', 'rgb(0,0,0)', '#123456;}</style><script>alert(1)</script>', '#123456" onload="x', "#123456\n" . 'body{}', '' ) as $bad ) {
	check( 'refused, and the starter\'s own colour used: ' . json_encode( $bad ), $d( array( 'accent' => $bad, 'accent_text' => $bad, 'background' => $bad ) ), $d( array() ) );
}
check( 'the colours a starter ships with', array_intersect_key( $d( array() ), TCGiant_Sync_Templates::DESIGN_COLOURS ), TCGiant_Sync_Templates::DESIGN_COLOURS );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIMAGES ARE THE SITE'S OWN ATTACHMENTS, OVER HTTPS\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['W']['images'] = array(
	601 => 'https://shop.example/logo.png',
	602 => 'http://shop.example/banner.jpg',
);
check( 'an attachment ID is kept as a number', $d( array( 'logo_id' => '601', 'banner_id' => 602 ) ), array_merge( $d( array() ), array( 'logo_id' => 601, 'banner_id' => 602 ) ) );
check( 'anything else is no image at all', array( $d( array( 'logo_id' => 'javascript:alert(1)' ) )['logo_id'], $d( array( 'banner_id' => '"><img src=x onerror=1>' ) )['banner_id'] ), array( 0, 0 ) );
check( 'an attachment that is an image: its https address', TCGiant_Sync_Templates::image_url( 601 ), 'https://shop.example/logo.png' );
check( '  an http one, on an https site, moved to https', TCGiant_Sync_Templates::image_url( 602 ), 'https://shop.example/banner.jpg' );
check( '  an ID that is not an image: none', TCGiant_Sync_Templates::image_url( 999 ), '' );
$GLOBALS['W']['home'] = 'http://shop.example';
check( '  on a site that is not https, an http one: none', TCGiant_Sync_Templates::image_url( 602 ), '' );
$GLOBALS['W']['home'] = 'https://shop.example';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPARTS ARE ON OR OFF; THE GALLERY STARTS OFF\n" . str_repeat( '=', 124 ) . "\n";

check( 'every part shows by default but the gallery', $d( array() )['show'], array( 'header' => true, 'short_description' => true, 'grading' => true, 'specifics' => true, 'returns' => true, 'handling' => true, 'gallery' => false ) );
check( 'a part turned off, and the gallery on', array( $d( array( 'show' => array( 'returns' => '0', 'gallery' => '1' ) ) )['show']['returns'], $d( array( 'show' => array( 'gallery' => 'on' ) ) )['show']['gallery'] ), array( false, true ) );
check( 'a part nobody knows is not kept', array_key_exists( 'script', $d( array( 'show' => array( 'script' => '<script>' ) ) )['show'] ), false );
check( 'nor a key nobody knows', array_keys( $d( array( 'css' => '}body{display:none', 'html' => '<script>' ) ) ), array( 'accent', 'accent_text', 'background', 'logo_id', 'banner_id', 'show' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT THE TEMPLATE READS\n" . str_repeat( '=', 124 ) . "\n";

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'logo_id' => 601 ) );
$v = TCGiant_Sync_Templates::design_values( $d( array( 'accent' => '#c0392b', 'background' => '#111111' ) ), TCGiant_Sync_Templates::settings() );
check( '{{design.x}} for the form\'s colours', array( $v['design.accent'], $v['design.accent_text'], $v['design.background'] ), array( '#c0392b', '#ffffff', '#111111' ) );
check( 'a dark page gets light text without anyone choosing it', array( $v['design.text'], $v['design.panel'] ), array( '#f2f2f2', '#262626' ) );
check( 'no logo chosen: the shop\'s', $v['design.logo_url'], 'https://shop.example/logo.png' );
check( '{{#show.x}} for each part', array( $v['show.header'], $v['show.gallery'] ), array( true, false ) );
$v = TCGiant_Sync_Templates::design_values( $d( array() ), TCGiant_Sync_Templates::settings() );
check( 'a light page, dark text', $v['design.text'], '#222222' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNOTHING TYPED INTO THE FORM REACHES THE OUTPUT AS MARKUP\n" . str_repeat( '=', 124 ) . "\n";

$attack = array(
	'accent'      => '#000000"><script>alert(1)</script>',
	'accent_text' => 'red;background:url(https://evil.example/x.png)',
	'background'  => '#fff}</style><script>alert(2)</script><style>',
	'logo_id'     => '601 onerror=alert(3)',
	'banner_id'   => 'https://evil.example/banner.png',
	'show'        => array( 'header' => '<b>yes</b>', 'evil' => '<script>alert(4)</script>' ),
);
$p   = new WC_Product( 41, array( 'name' => 'Card', 'description' => 'Plain.' ) );
$tpl = make_template( array(
	'html'   => '<div style="background:{{design.background}};color:{{design.text}}">{{#show.header}}<div style="background:{{design.accent}};color:{{design.accent_text}}"><img src="{{design.logo_url}}" alt="">{{#design.banner_url}}<img src="{{design.banner_url}}" alt="">{{/design.banner_url}}</div>{{/show.header}}{{{description}}}</div>',
	'css'    => '.tcg-desc { border-left: 4px solid {{design.accent}} } .x { background: {{design.logo_url}} }',
	'design' => $attack,
) );
update_post_meta( 41, TCGiant_Sync_Templates::OVERRIDE_META, (string) $tpl );

$stored = get_post_meta( $tpl, '_tpl_design', true );
check( 'what was saved is the clean form', $stored, $d( $attack ) );

$html = described( $p )['html'];
foreach ( array( '<script', 'alert(', 'evil.example', 'onerror', 'url(https://evil' ) as $needle ) {
	check( 'the description holds no "' . $needle . '"', false === stripos( $html, $needle ), true );
}
check( 'one stylesheet, closed once', array( substr_count( $html, '<style' ), substr_count( $html, '</style>' ) ), array( 1, 1 ) );
check( 'it holds the starter\'s own colours instead',false !== strpos( $html, 'background:#1f4e79;color:#ffffff' ), true );
check( 'the stylesheet takes colours only - a logo address in it renders empty', false !== strpos( $html, '.tcg-desc { border-left: 4px solid #1f4e79 } .x { background:  }' ), true );
check( 'the shop logo, as this site serves it', false !== strpos( $html, '<img src="https://shop.example/logo.png" alt="" style="max-width:100%;height:auto">' ), true );

// A link in the content takes the accent inline on a dark page, where a
// browser's own blue barely shows without the stylesheet.
$linked = new WC_Product( 49, array( 'name' => 'Linked', 'description' => '<p>See <a href="https://www.ebay.com/str/x">our Store</a>.</p>' ) );
$dark   = make_template( array( 'html' => '{{{description}}}', 'design' => array( 'accent' => '#d4a63a', 'accent_text' => '#141414', 'background' => '#141414' ) ) );
$light  = make_template( array( 'html' => '{{{description}}}', 'design' => array( 'accent' => '#1f4e79', 'accent_text' => '#ffffff', 'background' => '#ffffff' ) ) );
update_post_meta( 49, TCGiant_Sync_Templates::OVERRIDE_META, (string) $dark );
check( 'on a dark page a content link is coloured inline, with the accent', false !== strpos( described( $linked )['html'], '<a style="color:#d4a63a" href="https://www.ebay.com/str/x">' ), true );
update_post_meta( 49, TCGiant_Sync_Templates::OVERRIDE_META, (string) $light );
check( '  on a light page it is left as it is', false !== strpos( described( $linked )['html'], '<a href="https://www.ebay.com/str/x">our Store</a>' ), true );

done();
