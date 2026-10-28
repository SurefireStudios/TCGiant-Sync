<?php
/**
 * Harness: a WP-Lister for eBay template becomes a TCGiant draft.
 *
 * WP-Lister keeps a template as a folder - template.html, style.css with
 * $<field> variables, config.json with the seller's values, functions.php
 * registering the fields and their defaults, and more PHP - and downloads it
 * as a zip with that folder at the top. The converter reads only
 * template.html, style.css and config.json, and functions.php as text for its
 * defaults; it never runs a file and never unpacks one to disk. Out comes a
 * draft, through the editor's save path, and a report.
 *
 * The fixtures are our own, made here in WP-Lister's format, not WP-Lister's
 * files.
 *
 * Loaded for real: the converter, the templates and their save path, the
 * template screens' handlers and the WP-Lister notice, the renderer, the
 * sanitiser and the exporter.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

class Harness_Stop extends Exception {
	public $kind;
	public $data;
	public function __construct( $kind, $data = null ) { parent::__construct( $kind ); $this->kind = $kind; $this->data = $data; }
}
$GLOBALS['W']['nonce_ok'] = true;
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) { if ( ! $GLOBALS['W']['nonce_ok'] ) { throw new Harness_Stop( 'bad_nonce' ); } return 1; }
function wp_die( $message = '' ) { throw new Harness_Stop( 'died', $message ); }
function wp_safe_redirect( $url ) { throw new Harness_Stop( 'redirect', $url ); }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=nonce'; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function wc_get_attribute_taxonomies() { return array( (object) array( 'attribute_name' => 'brand', 'attribute_label' => 'Brand' ), (object) array( 'attribute_name' => 'groesse', 'attribute_label' => 'Größe' ) ); }
// The shortcodes this site has: only these lose their tags.
function shortcode_exists( $tag ) { return in_array( $tag, array( 'contact-form', 'gallery' ), true ); }
// What this site's PHP takes in an upload, and WordPress's way of writing a size.
function wp_max_upload_size() { return $GLOBALS['W']['max_upload'] ?? 8388608; }
function size_format( $bytes, $decimals = 0 ) { return $bytes >= 1048576 ? round( $bytes / 1048576, $decimals ) . ' MB' : round( $bytes / 1024, $decimals ) . ' KB'; }

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-wplister-converter.php';
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php';

$TMP      = sys_get_temp_dir() . '/tcg-wplister-harness-' . getmypid();
$SENTINEL = $TMP . '/functions-php-ran.txt';
@mkdir( $TMP, 0777, true );

// --- a template in WP-Lister's format, of our own ------------------------------

$HTML = <<<'HTML'
<div id="shop_wrap">
  <div class="top"><img src="https://shop.example/brand.png" alt="Brand"></div>
  <h2 class="name">[[product_title]]</h2>
  [[product_main_image]]
  <div class="pics">[[img_1]] [[img_2]] <a href="https://www.ebay.com/str/x">[[img_url_3]]</a> [[img_30]]</div>
  <div class="body">[[product_content]]</div>
  <div class="lead">[[product_excerpt_nl2br]]</div>
  <p>SKU [[product_sku]] / Brand [[attribute_Brand]] / Era [[attribute_Card Era]]</p>
  <p>Year [[meta_card_year]] Cost [[meta__cost_price]]</p>
  <p>Price [[product_price]] Item [[ebay_item_id]] Weight [[product_weight]]</p>
  [[product_gallery]] [[product_variations]]
  [[widget_featured_listings]]
  [[my_custom_thing]]
  <iframe src="https://video.example/x"></iframe>
  <script>track()</script>
  [contact-form id="3"]
  <h3>Shipping</h3><p>replace this text with your postage details</p>
  <h3>Returns</h3><p>We take them back.</p>
  <h3>Payment</h3><p>Cards.</p>
  <p><a href="[[ebay_store_url]]">Our Store</a></p>
  <span style="color:[[accent_color]]">x</span>
</div>
HTML;

$CSS = <<<'CSS'
/*
Template: Card Classic
Version: 2.1
Description: synthetic
*/
#shop_wrap h2.name { color: $accent_color; border-radius: $corner; }
#shop_wrap .top { background: $accent_color_dark; }
#shop_wrap > .body p { margin: 0 }
CSS;

$CONFIG = json_encode( array( 'accent_color' => '#aa0000', 'corner' => '6px' ) );

// It must never run: if it does, it leaves this file behind.
$FUNCTIONS = "<?php\nfile_put_contents( " . var_export( $SENTINEL, true ) . ", 'ran' );\n"
	. "function my_tpl_fields() {\n"
	. "\twplister_register_custom_fields( 'title', 'colours_title', '', 'Colours' );\n"
	. "\twplister_register_custom_fields( 'color', 'accent_color', '#0000ff', 'Accent' );\n"
	. "\twplister_register_custom_fields( \"color\", \"accent_color_dark\", \"#000033\", 'Accent, dark' );\n"
	. "\twplister_register_custom_fields( 'select', 'corner', '3px', 'Corners', array( 'options' => array( 'none' => '0px' ) ) );\n"
	. "\t// wplister_register_custom_fields( 'text', 'ghost_field', 'boo', 'Ghost' );\n"
	. "}\nadd_action( 'wplister_template_init', 'my_tpl_fields' );\n";

/** A zip of $files (path => text), as WP-Lister's Download link makes one. */
function make_zip( $path, array $files ) {
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $files as $name => $text ) {
		$zip->addFromString( $name, $text );
	}
	$zip->close();
	return $path;
}

$standard = array(
	'card-classic/template.html'      => $HTML,
	'card-classic/style.css'          => $CSS,
	'card-classic/config.json'        => $CONFIG,
	'card-classic/functions.php'      => $FUNCTIONS,
	'card-classic/header.php'         => '<?php echo "<meta name=viewport>"; ?>',
	'card-classic/footer.php'         => '',
	'card-classic/gallery_slider.php' => '<?php // a gallery ?>',
	'card-classic/images/logo.png'    => 'PNG',
	'card-classic/sub/template.html'  => '<p>deeper, never read</p>',
	'card-classic/lib/helper.php'     => '<?php // deeper',
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nREADING THE ZIP: IN MEMORY, CAPPED, ONE FOLDER DEEP\n" . str_repeat( '=', 124 ) . "\n";

$before = scandir( $TMP );
$read   = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/card-classic.zip', $standard ) );
check( 'the three files and functions.php, from the template\'s folder', array_keys( $read['files'] ), array( 'template.html', 'style.css', 'config.json', 'functions.php' ) );
check( '  the folder named', $read['folder'], 'card-classic' );
check( '  every PHP file noted, one in a subfolder too', $read['php'], array( 'functions.php', 'header.php', 'footer.php', 'gallery_slider.php', 'lib/helper.php' ) );
check( '  a deeper folder left alone, its template.html too', array( in_array( 'card-classic/sub/template.html', $read['ignored'], true ), in_array( 'card-classic/lib/helper.php', $read['ignored'], true ), in_array( 'card-classic/images/logo.png', $read['ignored'], true ) ), array( true, true, true ) );
check( 'nothing unpacked to disk', scandir( $TMP ), array_merge( $before, array( 'card-classic.zip' ) ) );
check( 'functions.php was never run', file_exists( $SENTINEL ), false );

$root = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/root.zip', array( 'template.html' => '<p>[[product_title]]</p>', 'style.css' => 'p{}' ) ) );
check( 'a zip with the files at its top, no folder: read', array_keys( $root['files'] ), array( 'template.html', 'style.css' ) );

foreach ( array( '../evil.html', 'card/../../wp-config.php', '/etc/passwd', 'C:/Windows/x.html', 'card\\..\\..\\x.php' ) as $bad ) {
	$r = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/bad.zip', array( 'card/template.html' => '<p>x</p>', $bad => 'x' ) ) );
	check( 'a path that leaves its folder refuses the whole zip: ' . $bad, is_wp_error( $r ) ? $r->get_error_code() : 'read', 'unsafe_path' );
}

$big = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/big.zip', array( 'card/template.html' => random_bytes( 2200000 ) ) ) );
check( 'a zip over 2 MB is refused', is_wp_error( $big ) ? $big->get_error_code() : 'read', 'too_large' );
$fat = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/fat.zip', array( 'card/template.html' => str_repeat( '<p>x</p>', 70000 ) ) ) );
check( 'a template.html over 512 KB is refused', is_wp_error( $fat ) ? $fat->get_error_code() : 'read', 'file_too_large' );
check( '  by its size in the zip, before it is unpacked', is_wp_error( $fat ) && false !== strpos( $fat->get_error_message(), 'so it is not a template file' ), true );
$two  = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/two.zip', array( 'first/template.html' => '<p>[[product_title]]</p>', 'second/template.html' => '<p>x</p>', 'first/images/a.png' => 'PNG' ) ) );
$skip = implode( ' | ', TCGiant_Sync_WPLister_Converter::convert( $two )['report']['skipped'] );
check( 'a second template folder, and the images, are listed as not read', array( false !== strpos( $skip, 'second' ), false !== strpos( $skip, '1 other file' ) ), array( true, true ) );
$with_view = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/view.zip', array( 'first/template.html' => '<p>x</p>', 'first/views/gallery.php' => '<?php // view', 'first/images/a.png' => 'PNG' ) ) );
$view_rep  = TCGiant_Sync_WPLister_Converter::convert( $with_view )['report'];
check( 'a PHP file in a subfolder is listed once, among those never run, not again as an other file (browser round 2, B1)', array( 1 === count( array_filter( $view_rep['dropped'], function ( $d ) { return 0 === strpos( $d, 'views/gallery.php' ); } ) ), false !== strpos( implode( ' | ', $view_rep['skipped'] ), '1 other file' ) ), array( true, true ) );
check( 'a zip over 2 MB: the message says it may be the images, and what to do', false !== strpos( $big->get_error_message(), 'images' ), true );
check( '  2 MB where the site takes more (round 3)', false !== strpos( $big->get_error_message(), 'larger than 2 MB' ), true );
$GLOBALS['W']['max_upload'] = 1048576;
check( '  the site\'s own upload limit where it is lower', false !== strpos( TCGiant_Sync_WPLister_Converter::too_large_message(), 'larger than 1 MB' ), true );
unset( $GLOBALS['W']['max_upload'] );
check( '  naming a template on this site only where there is one (round 2, N3)', false === strpos( $big->get_error_message(), 'on this site' ), true );
$none = TCGiant_Sync_WPLister_Converter::read_zip( make_zip( $TMP . '/none.zip', array( 'card/deep/template.html' => '<p>x</p>' ) ) );
check( 'a zip whose template is deeper than its first folder: no template', is_wp_error( $none ) ? $none->get_error_code() : 'read', 'no_template' );
file_put_contents( $TMP . '/notzip.zip', 'not a zip' );
check( 'a file that is not a zip', TCGiant_Sync_WPLister_Converter::read_zip( $TMP . '/notzip.zip' )->get_error_code(), 'not_zip' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE CONVERSION\n" . str_repeat( '=', 124 ) . "\n";

$out = TCGiant_Sync_WPLister_Converter::convert( $read );
$rep = $out['report'];
check( 'named from the stylesheet\'s Template: line', $out['name'], 'Card Classic (from WP-Lister)' );

$mapped = $rep['mapped'];
check( '[[product_title]] to {{title}}, [[product_content]] to {{{description}}}', array( $mapped['[[product_title]]'], $mapped['[[product_content]]'] ), array( '{{title}}', '{{{description}}}' ) );
check( '[[product_excerpt_nl2br]] to {{{short_description}}}, [[product_sku]] to {{sku}}', array( $mapped['[[product_excerpt_nl2br]]'], $mapped['[[product_sku]]'] ), array( '{{{short_description}}}', '{{sku}}' ) );
check( '[[ebay_store_url]] to {{ebay_store_url}}', $mapped['[[ebay_store_url]]'], '{{ebay_store_url}}' );
check( '[[product_main_image]] to an image of {{main_image}}', 0 === strpos( $mapped['[[product_main_image]]'], '{{#main_image}}<img src="{{main_image}}"' ), true );
check( '[[img_1]] to an image of {{image.1}}; [[img_url_3]] to {{image.3}}', array( 0 === strpos( $mapped['[[img_1]]'], '{{#image.1}}<img src="{{image.1}}"' ), $mapped['[[img_url_3]]'] ), array( true, '{{image.3}}' ) );
check( '  [[img_30]]: past the 24th, dropped and said', array( isset( $mapped['[[img_30]]'] ), count( array_filter( $rep['dropped'], function ( $d ) { return 0 === strpos( $d, '[[img_30]]' ); } ) ) ), array( false, 1 ) );
check( '[[product_gallery]] to {{{gallery}}}; [[product_variations]] to {{{variations_table}}}', array( $mapped['[[product_gallery]]'], $mapped['[[product_variations]]'] ), array( '{{{gallery}}}', '{{{variations_table}}}' ) );
check( '[[attribute_Brand]]: the shop\'s global attribute, by its label, as the name a custom one of that name fills too', $mapped['[[attribute_Brand]]'], '{{attr.brand}}' );
check( '[[attribute_Card Era]]: no such global attribute - its slug, and reported', array( $mapped['[[attribute_Card Era]]'], $rep['attributes'] ), array( '{{attr.card-era}}', array( 'Card Era' ) ) );
check( '[[meta_x]] to {{meta.x}}, each key listed for the allowlist', array( $mapped['[[meta_card_year]]'], $mapped['[[meta__cost_price]]'], $rep['allowlist'] ), array( '{{meta.card_year}}', '{{meta._cost_price}}', array( 'card_year', '_cost_price' ) ) );
$unsupported = implode( ' | ', $rep['unsupported'] );
check( 'unsupported: price, item number, weight, and one functions.php added, each with why', array( false !== strpos( $unsupported, '[[product_price]]: eBay shows the price itself' ), false !== strpos( $unsupported, '[[ebay_item_id]]: WP-Lister\'s own record' ), false !== strpos( $unsupported, '[[product_weight]]: not available' ), 1 === count( array_filter( $rep['unsupported'], function ( $u ) { return 0 === strpos( $u, '[[my_custom_thing]]' ); } ) ) ), array( true, true, true, true ) );
check( '  and taken out of the HTML', array( false === strpos( $out['html'], '[[' ), false === strpos( $out['html'], 'my_custom_thing' ) ), array( true, true ) );
$dropped = implode( ' | ', $rep['dropped'] );
check( 'dropped: the widget, the iframe, the script and the shortcode', array( false !== strpos( $dropped, '[[widget_featured_listings]]' ), false !== strpos( $dropped, 'iframes' ), false !== strpos( $dropped, 'scripts' ), false !== strpos( $dropped, '[contact-form]' ) ), array( true, true, true, true ) );
check( '  and every PHP file, never run', array( false !== strpos( $dropped, 'functions.php: PHP' ), false !== strpos( $dropped, 'header.php: PHP' ), false !== strpos( $dropped, 'gallery_slider.php: PHP' ) ), array( true, true, true ) );
check( '  the shortcode taken out of the HTML', false === strpos( $out['html'], 'contact-form' ), true );
check( '  and the iframe and the script out of the draft, not only listed', array( false === stripos( $out['html'], '<iframe' ), false === stripos( $out['html'], '<script' ), false === strpos( $out['html'], 'track()' ) ), array( true, true, true ) );

check( 'custom fields: config.json beats functions.php', $rep['fields']['accent_color'], '#aa0000 (config.json)' );
check( '  a default from functions.php where config.json says nothing', $rep['fields']['accent_color_dark'], '#000033 (functions.php)' );
check( '  written into the CSS, $accent_color never eating $accent_color_dark', array( false !== strpos( $out['css'], 'color: #aa0000; border-radius: 6px;' ), false !== strpos( $out['css'], 'background: #000033;' ) ), array( true, true ) );
check( '  and [[field]] into the HTML', false !== strpos( $out['html'], 'color:#aa0000' ), true );
$fonts = TCGiant_Sync_WPLister_Converter::convert( array(
	'files'   => array(
		'template.html' => '<p>[[product_title]]</p>',
		'style.css'     => 'p { font-family: $font; font-size: $font-size; } .x { content: "$font2" }',
		'config.json'   => json_encode( array( 'font' => 'Arial', 'font-size' => '14px' ) ),
	),
	'folder'  => 'fonts',
	'php'     => array(),
	'ignored' => array(),
) );
check( '  $font never eats the start of $font-size, nor of $font2, which is no field', array( false !== strpos( $fonts['css'], 'font-family: Arial; font-size: 14px;' ), false !== strpos( $fonts['css'], '"$font2"' ) ), array( true, true ) );
check( '  a section title registered with no default is not a field', isset( $rep['fields']['colours_title'] ), false );

// Fields as WP-Lister applies them (converter review, L2 and L3).
$fields = TCGiant_Sync_WPLister_Converter::convert( array(
	'files' => array(
		'template.html' => '<p class="[[tone]]">[[product_title]] &amp; $label</p>',
		'style.css'     => 'p { color: $ink; background: [[tone]]; border-radius: $radius; } .x { margin: $nowhere }',
		'config.json'   => json_encode( array( 'ink' => '#123456', 'tone' => 'pale', 'label' => 'Tom &amp; Jerry', 'stray' => 'never registered' ) ),
		'functions.php' => "<?php\nwplister_register_custom_fields( 'color', 'ink', '#000000', 'Ink' );\nwplister_register_custom_fields( 'text', 'tone', 'dark', 'Tone' );\nwplister_register_custom_fields( 'text', 'label', __( 'Our shop', 'theme' ), 'Label' );\nwplister_register_custom_fields( 'text', 'radius', \$default_radius, 'Corners' );\n",
	),
	'folder' => 'fields', 'php' => array( 'functions.php' ), 'ignored' => array(),
) );
$unresolved = implode( ' | ', $fields['report']['unresolved'] );
check( '  [[field]] in the CSS is written in too', false !== strpos( $fields['css'], 'background: pale;' ), true );
check( '  an entity in a value keeps its ";"', false !== strpos( $fields['html'], 'Tom &amp; Jerry' ), true );
$wrapped = TCGiant_Sync_WPLister_Converter::convert( array(
	'files'  => array(
		'template.html' => '<p>$motto</p>',
		'style.css'     => '',
		'functions.php' => "<?php\nwplister_register_custom_fields( 'text', 'motto', __( 'Cards since 1999', 'theme' ), 'Motto' );\n",
	),
	'folder' => 'wrapped', 'php' => array( 'functions.php' ), 'ignored' => array(),
) );
check( '  a default wrapped in __(), with no config.json value, is written in', array( $wrapped['report']['fields']['motto'] ?? '', trim( $wrapped['html'] ) ), array( 'Cards since 1999 (functions.php)', '<p>Cards since 1999</p>' ) );
check( '  a default wrapped in __() is read', $fields['report']['fields']['label'] ?? '', 'Tom &amp; Jerry (config.json)' );
check( '  a config.json key functions.php never registered is left out, and said', array( isset( $fields['report']['fields']['stray'] ), false !== strpos( $unresolved, '"stray"' ) ), array( false, true ) );
check( '  a $field nothing fills is listed, left as it is', array( false !== strpos( $unresolved, '$radius' ), false !== strpos( $unresolved, '$nowhere' ), false !== strpos( $fields['css'], '$radius' ) ), array( true, true, true ) );
$pasted_config = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<p>[[product_title]]</p>', 'p { color: $ink }', json_encode( array( 'ink' => '#abcdef' ) ) ) );
check( '  pasted with its config.json, and no functions.php: its values', false !== strpos( $pasted_config['css'], 'color: #abcdef' ), true );

// Placeholders as WP-Lister matches them: any characters (converter review, M1).
$labels = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<p>[[attribute_Card #]] [[attribute_Grade:]] [[attribute_Set, Year]] [[attribute_GRÖßE]] [[meta_año]]</p>', '' ) );
check( '  labels with #, :, a comma or accents are mapped, none left for buyers', array( false === strpos( $labels['html'], '[[' ), $labels['report']['mapped']['[[attribute_GRÖßE]]'] ?? '' ), array( true, '{{attr.groesse}}' ) );
check( '  an accented label matches the shop\'s attribute whatever its case', in_array( 'GRÖßE', $labels['report']['attributes'], true ), false );
$nested = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<p>[[attribute_[odd]]]</p>', '' ) );
// Fields registered in a loop: the pattern reads none, so config.json goes in whole (N2).
$looped = TCGiant_Sync_WPLister_Converter::convert( array(
	'files' => array(
		'template.html' => '<p style="color:[[tone]]">[[product_title]]</p>',
		'style.css'     => 'p { color: $ink; } .b { color: $other }',
		'config.json'   => json_encode( array( 'ink' => '#111111', 'other' => '#222222', 'tone' => '#333333' ) ),
		'functions.php' => "<?php\nforeach ( \$fields as \$slug => \$default ) {\n\twplister_register_custom_fields( 'color', \$slug, \$default, \$slug );\n}\n",
	),
	'folder' => 'looped', 'php' => array( 'functions.php' ), 'ignored' => array(),
) );
check( '  fields registered in a loop: config.json written in whole, and said', array( false !== strpos( $looped['css'], 'color: #111111;' ), false !== strpos( $looped['css'], 'color: #222222' ), false !== strpos( $looped['html'], 'color:#333333' ), 1 === count( array_filter( $looped['report']['unresolved'], function ( $u ) { return false !== strpos( $u, 'cannot read' ); } ) ) ), array( true, true, true, true ) );
$used = TCGiant_Sync_WPLister_Converter::convert( array(
	'files' => array(
		'template.html' => '<p>[[product_title]] [[tone]]</p>',
		'style.css'     => 'p { color: $ink }',
		'config.json'   => json_encode( array( 'ink' => '#444444', 'tone' => 'calm', 'idle' => 'x' ) ),
		'functions.php' => "<?php\nwplister_register_custom_fields( 'text', 'other', 'y', 'Other' );\n",
	),
	'folder' => 'used', 'php' => array( 'functions.php' ), 'ignored' => array(),
) );
check( '  a config key the template uses goes in though functions.php names others; one unused is left out', array( false !== strpos( $used['css'], 'color: #444444' ), false !== strpos( $used['html'], 'calm' ), isset( $used['report']['fields']['idle'] ) ), array( true, true, false ) );
$prices = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<p>Only $AUD 12, or $USD 9</p><img src="https://shop.example/a.png" alt="$alt_text">', 'p { color: $nowhere }' ) );
$prices_left = implode( ' | ', $prices['report']['unresolved'] );
$styled = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<style>p { color: $c }</style><p>Only $AUD 5</p>', '' ) );
check( '  a $field in a <style> block in the HTML is listed too (round 3)', array( false !== strpos( implode( ' | ', $styled['report']['unresolved'] ), '$c:' ), false === strpos( implode( ' | ', $styled['report']['unresolved'] ), '$AUD' ) ), array( true, true ) );
check( '  "$AUD 12" in the text is a price, not a field; one in the CSS or an attribute is listed', array( false === strpos( $prices_left, '$AUD' ), false !== strpos( $prices_left, '$alt_text' ), false !== strpos( $prices_left, '$nowhere' ) ), array( true, true, true ) );
check( '  a [[placeholder]] with brackets inside, which nothing maps, is listed as left in', 1 === count( array_filter( $nested['report']['unresolved'], function ( $u ) { return false !== strpos( $u, 'buyers would see it as text' ); } ) ), true );
check( '  a meta key this cannot read is said', 1 === count( array_filter( $labels['report']['unsupported'], function ( $u ) { return 0 === strpos( $u, '[[meta_año]]' ); } ) ), true );

// Bracketed text is text; only this site's shortcodes lose their tags (L4).
$brackets = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<p>Condition: [NM] - shipped [same day] - see [b]notes[/b] [gallery ids="1"]Photos[/gallery]</p>', '' ) );
check( '  [NM], [same day] and [b]notes[/b] stay; a shortcode keeps its text, not its tags', trim( $brackets['html'] ), '<p>Condition: [NM] - shipped [same day] - see [b]notes[/b] Photos</p>' );
check( 'the report says what it took out: an unknown private meta key is flagged', false !== strpos( implode( ' | ', $rep['suggestions'] ), '_cost_price starts with "_"' ), true );
check( '  nor a call left commented out', isset( $rep['fields']['ghost_field'] ), false );
check( 'functions.php was still never run', file_exists( $SENTINEL ), false );

// A German template's own headings, on a German site (L6).
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_DE';
$german = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<h3>Versand</h3><p>DHL.</p><h3>Rückgabe</h3><p>14 Tage.</p><h3>Zahlung</h3><p>PayPal.</p>', '' ) );
$gs     = implode( ' | ', $german['report']['suggestions'] );
check( 'German headings: Versand and Rückgabe suggested as blocks, said to be English only', array( false !== strpos( $gs, '"Versand"' ) && false !== strpos( $gs, '{{{policy.handling}}}' ), false !== strpos( $gs, '"Rückgabe"' ) && false !== strpos( $gs, '{{{policy.returns}}}' ), false !== strpos( $gs, 'English only' ) ), array( true, true, true ) );
check( '  and Zahlung as payment text to take out', false !== strpos( $gs, '"Zahlung" lists payment methods' ), true );
$de_draft = TCGiant_Sync_WPLister_Converter::save_draft( TCGiant_Sync_WPLister_Converter::from_paste( '<p>[[product_title]]</p>', '' ), 1 );
check( '  the draft is in the shop\'s language', TCGiant_Sync_Templates::get( $de_draft['id'] )['lang'], 'de' );
TCGiant_Sync_OAuth::$settings['marketplace'] = 'EBAY_US';

$suggest = implode( ' | ', $rep['suggestions'] );
check( 'Shipping and Returns sections: suggested policy blocks', array( false !== strpos( $suggest, '"Shipping"' ) && false !== strpos( $suggest, '{{{policy.handling}}}' ), false !== strpos( $suggest, '"Returns"' ) && false !== strpos( $suggest, '{{{policy.returns}}}' ) ), array( true, true ) );
check( '  Payment: suggested out', false !== strpos( $suggest, '"Payment" lists payment methods' ), true );
check( '  and the filler text found', false !== strpos( $suggest, 'replace this text' ), true );

// A field value cannot carry markup into the HTML or the stylesheet.
$hostile = TCGiant_Sync_WPLister_Converter::convert( array( 'files' => array( 'template.html' => '<p style="color:[[c]]">x</p>', 'style.css' => 'p { color: $c }', 'config.json' => json_encode( array( 'c' => 'red;}</style><script>alert(1)</script>' ) ) ) ) );
check( 'a field value is written in as text, no markup', array( false === strpos( $hostile['html'] . $hostile['css'], '<script' ), false === strpos( $hostile['css'], '}</style' ) ), array( true, true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE DRAFT, AND HOW IT RENDERS\n" . str_repeat( '=', 124 ) . "\n";

$saved = TCGiant_Sync_WPLister_Converter::save_draft( $read, 1 );
$draft = TCGiant_Sync_Templates::get( $saved['id'] );
check( 'saved as a draft, version 1, through the save path', array( $draft['status'], $draft['version'], $draft['name'] ), array( 'draft', 1, 'Card Classic (from WP-Lister)' ) );
check( '  nothing assigned to it', TCGiant_Sync_Templates::assigned( $saved['id'] ), false );

$GLOBALS['W']['images'] = array( 901 => 'https://shop.example/a.jpg', 902 => 'https://shop.example/b.jpg' );
$GLOBALS['W']['term_names']['pa_brand|5'] = 'Topps';
$p = new WC_Product( 95, array( 'name' => 'Rookie Card', 'description' => 'Sharp corners.', 'sku' => 'RC-1', 'image_id' => 901, 'gallery' => array( 902 ), 'attributes' => array( new WC_Product_Attribute( 'pa_brand', array( 5 ), true ) ) ) );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'meta_allowlist' => array( 'card_year' ), 'ebay_store_url' => 'https://www.ebay.com/str/x' ) );
update_post_meta( 95, 'card_year', '1987' );
$r = described( $p, array( 'template_draft' => $draft ) );
$h = $r['html'];
check( 'the converted draft renders: title, description, SKU and brand', array( false !== strpos( $h, 'Rookie Card</h2>' ), false !== strpos( $h, 'Sharp corners.' ), false !== strpos( $h, 'SKU RC-1 / Brand Topps' ) ), array( true, true, true ) );
check( '  images by number, the second and none past the last', array( false !== strpos( $h, 'src="https://shop.example/b.jpg"' ), substr_count( $h, 'shop.example/a.jpg' ) >= 2 ), array( true, true ) );
check( '  the allowlisted meta, and not the cost', false !== strpos( $h, 'Year 1987 Cost </p>' ), true );
check( '  no script, iframe or widget reaches eBay', array( false === stripos( $h, '<script' ), false === stripos( $h, '<iframe' ), false === stripos( $h, 'track()' ) ), array( true, true, true ) );
check( '  its #shop_wrap rules still find their element', array( false !== strpos( $h, 'id="shop_wrap"' ), false !== strpos( $h, '#shop_wrap > .body p { margin: 0 }' ) ), array( true, true ) );
check( '  the Store link stays, as eBay\'s own', false !== strpos( $h, '<a href="https://www.ebay.com/str/x">Our Store</a>' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHIS SITE'S OWN WP-LISTER TEMPLATES, AND PASTED ONES\n" . str_repeat( '=', 124 ) . "\n";

$uploads = wp_upload_dir()['basedir'] . '/wp-lister/templates/shop-default';
@mkdir( $uploads, 0777, true );
file_put_contents( $uploads . '/template.html', '<div>[[product_title]] [[product_content]]</div>' );
file_put_contents( $uploads . '/style.css', "/*\nTemplate: Shop Default\n*/\ndiv { color: \$ink }" );
file_put_contents( $uploads . '/config.json', json_encode( array( 'ink' => '#123456' ) ) );
file_put_contents( $uploads . '/functions.php', "<?php\nfile_put_contents( " . var_export( $SENTINEL, true ) . ", 'ran' );\nwplister_register_custom_fields( 'color', 'ink', '#000000', 'Ink' );" );
@mkdir( $uploads . '/views', 0777, true );
file_put_contents( $uploads . '/views/gallery.php', '<?php // a view' );
check( 'found where WP-Lister keeps them', array_keys( TCGiant_Sync_WPLister_Converter::installed() ), array( 'shop-default' ) );
$local = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::read_installed( 'shop-default' ) );
check( '  read and converted', array( $local['name'], trim( $local['html'] ), false !== strpos( $local['css'], 'color: #123456' ) ), array( 'Shop Default (from WP-Lister)', '<div>{{title}} {{{description}}}</div>', true ) );
check( '  its functions.php never run', file_exists( $SENTINEL ), false );
check( '  with a template on this site, the 2 MB message names it', false !== strpos( TCGiant_Sync_WPLister_Converter::too_large_message(), 'One of the WP-Lister templates on this site' ), true );
check( '  a PHP file in a subfolder listed among those never run', in_array( 'views/gallery.php', TCGiant_Sync_WPLister_Converter::read_installed( 'shop-default' )['php'], true ), true );
check( '  a name that is not one of them is refused, a path most of all', array( TCGiant_Sync_WPLister_Converter::read_installed( '../../..' )->get_error_code(), TCGiant_Sync_WPLister_Converter::read_installed( 'nope' )->get_error_code() ), array( 'no_template', 'no_template' ) );

$pasted = TCGiant_Sync_WPLister_Converter::convert( TCGiant_Sync_WPLister_Converter::from_paste( '<p>[[product_title]]</p>', 'p { color: red }' ) );
check( 'pasted HTML and CSS, for a server without the zip extension', array( trim( $pasted['html'] ), trim( $pasted['css'] ) ), array( '<p>{{title}}</p>', 'p { color: red }' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SCREEN: NONCE, CAPABILITY, A REAL UPLOAD\n" . str_repeat( '=', 124 ) . "\n";

$admin = new TCGiant_Sync_Template_Admin();
$count = function () { return count( TCGiant_Sync_Templates::all() ); };
$run   = function ( array $post, array $files = array() ) use ( $admin ) {
	$_POST  = $post;
	$_FILES = $files;
	try {
		$admin->handle_convert();
	} catch ( Harness_Stop $stop ) {
		return $stop->kind;
	}
	return 'returned';
};

$n = $count();
$GLOBALS['W']['nonce_ok'] = false;
check( 'a bad nonce: refused, nothing converted', array( $run( array( 'source' => 'paste', 'paste_html' => '<p>x</p>' ) ), $count() ), array( 'bad_nonce', $n ) );
$GLOBALS['W']['nonce_ok'] = true;
$GLOBALS['W']['caps'] = array( 'manage_options' => false );
check( 'without manage_options: refused, nothing converted', array( $run( array( 'source' => 'paste', 'paste_html' => '<p>x</p>' ) ), $count() ), array( 'died', $n ) );
$GLOBALS['W']['caps'] = array( 'manage_options' => true );
$zip = make_zip( $TMP . '/upload.zip', $standard );
check( 'a "zip" PHP did not receive as an upload is refused', array( $run( array( 'source' => 'upload' ), array( 'wplister_zip' => array( 'tmp_name' => $zip, 'size' => filesize( $zip ) ) ) ), $count() ), array( 'redirect', $n ) );
check( 'pasted, with both: a draft', array( $run( array( 'source' => 'paste', 'paste_html' => '<p>[[product_title]]</p>', 'paste_css' => '' ) ), $count() ), array( 'redirect', $n + 1 ) );
check( 'pasted with the upload radio left ticked: still a draft, from what was filled', array( $run( array( 'source' => 'upload', 'paste_html' => '<p>[[product_title]]</p>' ) ), $count() ), array( 'redirect', $n + 2 ) );
$said = function () {
	$notice = get_transient( 'tcgiant_tpl_notice_1' );
	delete_transient( 'tcgiant_tpl_notice_1' );
	return is_array( $notice ) ? $notice['text'] : '';
};
$said();
// The folder list always posts a folder: never taken for one chosen (round 2, N1).
check( 'Upload ticked, no file, nothing pasted: refused, not the first folder converted', array( $run( array( 'source' => 'upload', 'installed' => 'shop-default' ), array( 'wplister_zip' => array( 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 ) ) ), $count(), false !== strpos( $said(), 'Choose the .zip' ) ), array( 'redirect', $n + 2, true ) );
check( 'a zip PHP refused as too large: said so, nothing converted', array( $run( array( 'source' => 'upload', 'installed' => 'shop-default', 'paste_html' => '<p>x</p>' ), array( 'wplister_zip' => array( 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ) ) ), $count(), false !== strpos( $said(), 'larger than 2 MB' ) ), array( 'redirect', $n + 2, true ) );
check( 'a template on this site ticked, with HTML pasted too: it asks which', array( $run( array( 'source' => 'installed', 'installed' => 'shop-default', 'paste_html' => '<p>x</p>' ) ), $count(), false !== strpos( $said(), 'Leave only the one' ) ), array( 'redirect', $n + 2, true ) );
check( 'Paste ticked with nothing pasted: refused, no empty draft', array( $run( array( 'source' => 'paste', 'paste_html' => '  ' ) ), $count(), false !== strpos( $said(), 'nothing to convert' ) ), array( 'redirect', $n + 2, true ) );
check( 'Paste ticked, nothing pasted, a zip chosen: the zip', array( $run( array( 'source' => 'paste' ), array( 'wplister_zip' => array( 'tmp_name' => $zip, 'size' => filesize( $zip ), 'error' => UPLOAD_ERR_OK ) ) ), false !== strpos( $said(), 'Choose the .zip' ) ), array( 'redirect', true ) );
// A folder whose name sanitize_file_name() would change is still found by its exact name (L5).
$spaced = wp_upload_dir()['basedir'] . '/wp-lister/templates/My Shop';
@mkdir( $spaced, 0777, true );
file_put_contents( $spaced . '/template.html', '<p>[[product_title]]</p>' );
check( 'a folder named "My Shop" converts', array( $run( array( 'source' => 'installed', 'installed' => 'My Shop' ) ), $count() ), array( 'redirect', $n + 3 ) );
@unlink( $spaced . '/template.html' );
@rmdir( $spaced );
check( '  and its report kept for the screen', is_array( get_transient( 'tcgiant_tpl_convert_1' )['report'] ?? null ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE NOTICE WHILE WP-LISTER IS ACTIVE\n" . str_repeat( '=', 124 ) . "\n";

$notice = function () use ( $admin ) {
	ob_start();
	$admin->wplister_notice();
	return ob_get_clean();
};
update_option( 'wplister_create_orders', '1' );
check( 'WP-Lister not active: no notice, whatever its settings say', $notice(), '' );
delete_option( 'wplister_create_orders' );
define( 'WPLE_PLUGIN_VERSION', '3.8.8' );
check( 'active, with its order creation and stock sync off: no notice', $notice(), '' );
update_option( 'wplister_handle_stock', '1' );
check( '  its stock sync on: the notice', '' !== $notice(), true );
update_option( 'wplister_handle_stock', '0' );
update_option( 'wplister_create_orders', '0' );
check( '  both set off: none', $notice(), '' );
update_option( 'wplister_create_orders', '1' );
check( 'active: the notice, about orders and stock', array( false !== strpos( $notice(), 'WP-Lister for eBay is active too' ), false !== strpos( $notice(), 'turn off its order creation and its stock sync' ) ), array( true, true ) );
$GLOBALS['W']['caps'] = array( 'manage_options' => false );
check( '  only for manage_options', $notice(), '' );
$GLOBALS['W']['caps'] = array( 'manage_options' => true );
try {
	$admin->handle_dismiss_wplister();
} catch ( Harness_Stop $stop ) {
	unset( $stop );
}
check( 'dismissed: gone for this user', $notice(), '' );
$GLOBALS['W']['user_meta'] = array();
check( '  and still there for another', '' !== $notice(), true );

// tidy up
foreach ( array_merge( glob( $TMP . '/*' ) ?: array(), glob( $uploads . '/*' ) ?: array() ) as $f ) {
	@unlink( $f );
}
@rmdir( $uploads );
@rmdir( $TMP );

done();
