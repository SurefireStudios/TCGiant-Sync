<?php
/**
 * Harness: the template screens refuse what they must.
 *
 * Every save, import and assignment needs the plugin's own capability,
 * manage_options, and a nonce; a product's template follows the rest of its
 * eBay tab, which needs edit_post. A template's HTML is stored as typed, so it
 * is printed only escaped - esc_textarea() in the editor - or after the
 * sanitiser, and the preview shows it in an iframe whose sandbox allows
 * nothing. WP-Lister shipped a stored-XSS fix in 2026; this is that class of
 * fault, tested for.
 *
 * Loaded for real: TCGiant_Sync_Template_Admin (every handler), the editor's
 * view, the templates, the exporter and the sanitiser.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// --- the admin, played --------------------------------------------------------

class Harness_Stop extends Exception {
	public $kind;
	public $data;
	public function __construct( $kind, $data = null ) { parent::__construct( $kind ); $this->kind = $kind; $this->data = $data; }
}
$GLOBALS['W']['nonce_ok'] = true;
$GLOBALS['W']['caps']     = array( 'manage_options' => true, 'edit_post' => true );
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) { if ( ! $GLOBALS['W']['nonce_ok'] ) { throw new Harness_Stop( 'bad_nonce' ); } return 1; }
function check_ajax_referer( $action = -1, $arg = false, $die = true ) { if ( ! $GLOBALS['W']['nonce_ok'] ) { throw new Harness_Stop( 'bad_nonce' ); } return 1; }
function wp_verify_nonce( $nonce, $action = -1 ) { return $GLOBALS['W']['nonce_ok'] ? 1 : false; }
function wp_die( $message = '' ) { throw new Harness_Stop( 'died', $message ); }
function wp_safe_redirect( $url ) { throw new Harness_Stop( 'redirect', $url ); }
function wp_send_json_success( $data = null ) { throw new Harness_Stop( 'json_ok', $data ); }
function wp_send_json_error( $data = null ) { throw new Harness_Stop( 'json_error', $data ); }
function nocache_headers() {}
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=nonce'; }
function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function disabled( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' disabled="disabled"' : ''; if ( $echo ) { echo $out; } return $out; }
function get_userdata( $id ) { return false; }
function wc_get_attribute_taxonomies() { return array(); }
function get_edit_post_link( $id ) { return 'post.php?post=' . (int) $id; }

/** Run a handler; what stopped it, and with what. */
function run( $callback ) {
	try {
		call_user_func( $callback );
	} catch ( Harness_Stop $stop ) {
		return array( $stop->kind, $stop->data );
	}
	return array( 'returned', null );
}

require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php';
$admin = new TCGiant_Sync_Template_Admin();

$tpl   = make_template( array( 'name' => 'Clean', 'html' => '<div>{{{description}}}</div>' ) );
$other = make_template( array( 'name' => 'Other', 'html' => '<div>other</div>' ) );
$state = function () {
	return json_encode( array( $GLOBALS['W']['options'], array_map( function ( $p ) { return array( $p->post_title, $p->post_status ); }, $GLOBALS['W']['posts'] ), $GLOBALS['W']['meta'] ) );
};

$handlers = array(
	'save'        => function () use ( $admin, $tpl ) { $_POST = array( 'template_id' => $tpl, 'name' => 'Hacked', 'html' => '<script>x()</script>', 'status' => 'publish' ); $admin->handle_save(); },
	'new'         => function () use ( $admin ) { $_REQUEST = array( 'do' => 'new' ); $admin->handle_action(); },
	'duplicate'   => function () use ( $admin, $tpl ) { $_REQUEST = array( 'do' => 'duplicate', 'id' => $tpl ); $admin->handle_action(); },
	'delete'      => function () use ( $admin, $other ) { $_REQUEST = array( 'do' => 'delete', 'id' => $other ); $admin->handle_action(); },
	'set default' => function () use ( $admin, $tpl ) { $_REQUEST = array( 'do' => 'default', 'id' => $tpl ); $admin->handle_action(); },
	'restore'     => function () use ( $admin, $tpl ) { $_REQUEST = array( 'do' => 'restore', 'id' => $tpl, 'version' => 1 ); $admin->handle_action(); },
	'export'      => function () use ( $admin, $tpl ) { $_REQUEST = array( 'do' => 'export', 'id' => $tpl ); $admin->handle_action(); },
	'import'      => function () use ( $admin ) { $_POST = array( 'template_json' => json_encode( array( 'format' => 'tcgiant-template/1', 'name' => 'In', 'html' => 'x' ) ) ); $admin->handle_import(); },
	'settings'    => function () use ( $admin, $tpl ) { $_POST = array( 'tpl' => array( 'default_template_id' => $tpl ), 'bulk_push_cap' => 60 ); $admin->handle_settings(); },
	'block'       => function () use ( $admin ) { $_POST = array( 'slug' => 'about', 'label' => 'About', 'html' => array( 'en' => '<p>x</p>' ) ); $admin->handle_block(); },
	'preview'     => function () use ( $admin, $tpl ) { $_POST = array( 'product_id' => 101, 'template_id' => $tpl ); $admin->ajax_preview(); },
	'check'       => function () use ( $admin, $tpl ) { $_POST = array( 'product_id' => 101, 'template_id' => $tpl ); $admin->ajax_check_ebay(); },
	'products'    => function () use ( $admin ) { $_POST = array( 'term' => '' ); $admin->ajax_products(); },
);
new WC_Product( 101, array( 'name' => 'Card', 'description' => 'x' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA BAD NONCE IS REFUSED, AND CHANGES NOTHING\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['W']['nonce_ok'] = false;
foreach ( $handlers as $name => $handler ) {
	$before = $state();
	$GLOBALS['API']->calls = array();
	check( $name . ': refused', run( $handler )[0], 'bad_nonce' );
	check( '  nothing changed, nothing asked of eBay', array( $state() === $before, $GLOBALS['API']->calls ), array( true, array() ) );
}
$GLOBALS['W']['nonce_ok'] = true;

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWITHOUT manage_options, REFUSED, AND NOTHING CHANGES\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['W']['caps']['manage_options'] = false;
foreach ( $handlers as $name => $handler ) {
	$before = $state();
	$GLOBALS['API']->calls = array();
	$stop   = run( $handler )[0];
	check( $name . ': refused', in_array( $stop, array( 'died', 'json_error' ), true ), true );
	check( '  nothing changed, nothing asked of eBay', array( $state() === $before, $GLOBALS['API']->calls ), array( true, array() ) );
}
check( 'the screen itself is refused', run( function () use ( $admin ) { $admin->render_page(); } )[0], 'died' );
$GLOBALS['W']['caps']['manage_options'] = true;

// The same handlers do their work with both: the refusals above are theirs.
check( 'with both, Save saves', array( run( $handlers['save'] )[0], TCGiant_Sync_Templates::get( $tpl )['name'] ), array( 'redirect', 'Hacked' ) );
check( 'with both, the settings are saved', array( run( $handlers['settings'] )[0], TCGiant_Sync_Templates::settings()['default_template_id'], TCGiant_Sync_Exporter::bulk_push_cap() ), array( 'redirect', $tpl, 60 ) );
check( 'with both, Import imports', run( $handlers['import'] )[0], 'redirect' );

// An upload must be one PHP received: a path to any file on the server is not.
$tmp = tempnam( sys_get_temp_dir(), 'tcg' );
file_put_contents( $tmp, json_encode( array( 'format' => 'tcgiant-template/1', 'name' => 'Sneaked', 'html' => 'x' ) ) );
$_FILES = array( 'template_file' => array( 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'name' => 'x.json' ) );
$count  = count( TCGiant_Sync_Templates::all() );
run( function () use ( $admin ) { $_POST = array(); $admin->handle_import(); } );
check( 'an "upload" that is not one PHP received is refused', count( TCGiant_Sync_Templates::all() ), $count );
$_FILES = array();
unlink( $tmp );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA PRODUCT'S TEMPLATE FOLLOWS ITS eBAY TAB: edit_post\n" . str_repeat( '=', 124 ) . "\n";

$draft = make_template( array( 'name' => 'Draft', 'status' => 'draft' ) );
$post  = array( '_tcgiant_tpl_fields' => '1', '_tcgiant_tpl_override' => (string) $other, '_tcgiant_ebay_description' => wp_slash( 'For eBay \\ only' ) );
$GLOBALS['W']['caps']['edit_post'] = false;
check( 'without edit_post nothing is saved', array( TCGiant_Sync_Template_Admin::save_product_fields( 101, $post ), get_post_meta( 101, TCGiant_Sync_Templates::OVERRIDE_META, true ) ), array( false, '' ) );
$GLOBALS['W']['caps']['edit_post'] = true;
TCGiant_Sync_Template_Admin::save_product_fields( 101, $post );
check( 'with it, a published template and the eBay-only description are saved', array( get_post_meta( 101, TCGiant_Sync_Templates::OVERRIDE_META, true ), get_post_meta( 101, TCGiant_Sync_Templates::EBAY_DESCRIPTION_META, true ) ), array( (string) $other, 'For eBay \\ only' ) );
TCGiant_Sync_Template_Admin::save_product_fields( 101, array( '_tcgiant_tpl_fields' => '1', '_tcgiant_tpl_override' => (string) $draft ) );
check( 'a draft cannot be chosen: back to following the rules', get_post_meta( 101, TCGiant_Sync_Templates::OVERRIDE_META, true ), '' );
TCGiant_Sync_Template_Admin::save_product_fields( 101, array( '_tcgiant_tpl_fields' => '1', '_tcgiant_tpl_override' => '<script>' ) );
check( 'nor anything that is not a template', get_post_meta( 101, TCGiant_Sync_Templates::OVERRIDE_META, true ), '' );
TCGiant_Sync_Template_Admin::save_product_fields( 101, array( '_tcgiant_tpl_override' => 'none' ) );
check( 'a save without the panel on screen changes nothing', get_post_meta( 101, TCGiant_Sync_Templates::OVERRIDE_META, true ), '' );

$admin_src = file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
check( 'the product save asks for the panel\'s own nonce first', false !== strpos( $admin_src, "wp_verify_nonce( sanitize_key( wp_unslash( \$_POST['_tcgiant_tpl_nonce'] ) ), 'tcgiant_tpl_product' )" ), true );

new WC_Product( 102, array( 'name' => 'Theirs' ) );
$GLOBALS['W']['caps']['edit_post'] = false;
$admin->handle_bulk_actions( 'edit.php', TCGiant_Sync_Template_Admin::BULK_PREFIX . $other, array( 102 ) );
check( 'the bulk action skips a product the user cannot edit', get_post_meta( 102, TCGiant_Sync_Templates::OVERRIDE_META, true ), '' );
$GLOBALS['W']['caps']['edit_post'] = true;
$admin->handle_bulk_actions( 'edit.php', TCGiant_Sync_Template_Admin::BULK_PREFIX . $draft, array( 102 ) );
check( '  and refuses a template that is not published', get_post_meta( 102, TCGiant_Sync_Templates::OVERRIDE_META, true ), '' );
$admin->handle_bulk_actions( 'edit.php', TCGiant_Sync_Template_Admin::BULK_PREFIX . $other, array( 102 ) );
check( '  and sets one that is', get_post_meta( 102, TCGiant_Sync_Templates::OVERRIDE_META, true ), (string) $other );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA STORED BODY IS ONLY EVER PRINTED ESCAPED\n" . str_repeat( '=', 124 ) . "\n";

$evil = TCGiant_Sync_Templates::save( 0, array(
	'name' => 'Evil <b onmouseover=alert(1)>name</b>',
	'html' => '</textarea><script>alert("html")</script><div>{{{description}}}</div>',
	'css'  => '</textarea><img src=x onerror=alert("css")>',
), 1 );
TCGiant_Sync_Templates::save( $evil, array( 'html' => 'v2 </textarea><script>alert("v2")</script>' ), 1 );
$_GET = array( 'id' => $evil, 'view' => 'edit' );
ob_start();
include dirname( __DIR__ ) . '/admin/views/template-edit.php';
$page = ob_get_clean();
check( 'the editor drew the page', false !== strpos( $page, 'id="tc-template-form"' ), true );
check( 'no script from the body reaches the page', false === stripos( $page, '<script>alert' ), true );
check( 'nor an onerror from the stylesheet', false === stripos( $page, '<img src=x onerror' ), true );
check( 'the body is in its textarea, escaped', false !== strpos( $page, '&lt;/textarea&gt;&lt;script&gt;alert(&quot;' ) || false !== strpos( $page, '&lt;/textarea&gt;&lt;script&gt;alert(&#039;' ) || false !== strpos( $page, '&lt;/textarea&gt;&lt;script&gt;alert("' ), true );
check( 'the name is escaped', false === strpos( $page, '<b onmouseover' ), true );
check( 'an old version, in the Versions drawer, is escaped too', false !== strpos( $page, '&lt;script&gt;alert(&quot;html&quot;)' ), true );
check( 'the preview frame\'s sandbox allows nothing', false !== strpos( $page, '<iframe id="tc-preview-frame" sandbox=""' ), true );

// Every other view draws too, with the name escaped wherever it shows.
function get_terms( $args = array() ) { return array( (object) array( 'term_id' => 5, 'parent' => 0, 'name' => 'Cards <i>x</i>' ) ); }
function wp_date( $format, $ts = null ) { return gmdate( 'Y-m-d', (int) $ts ); }
TCGiant_Sync_Templates::save( $evil, array( 'status' => 'publish' ) );
TCGiant_Sync_Templates::save_block( 'evil', '<script>alert("block")</script>', array( 'en' => '</textarea><script>alert("blk")</script>' ) );
foreach ( array( 'template-list.php' => array(), 'template-blocks.php' => array(), 'template-settings.php' => array(), 'template-preview.php' => array( 'id' => $evil, 'product' => 101 ), 'template-convert.php' => array() ) as $view => $get ) {
	$_GET = $get;
	ob_start();
	include dirname( __DIR__ ) . '/admin/views/' . $view;
	$drawn = ob_get_clean();
	check( $view . ': drawn, with no stored markup in it', array( strlen( $drawn ) > 200, false === stripos( $drawn, '<script>alert' ), false === strpos( $drawn, '<b onmouseover' ), false === strpos( $drawn, '<i>x</i>' ) ), array( true, true, true, true ) );
	if ( 'template-blocks.php' === $view ) {
		// The shop's own policies, with no listing in sight (browser review, L8).
		check( '  its policy rows speak of the shop, not "this listing"', array( false === strpos( $drawn, 'this listing' ), false !== stripos( $drawn, 'your shop sends' ) ), array( true, true ) );
	}
}

// On a narrow screen a table's rows stack and each cell shows its column's
// heading from data-label: every cell must carry it, in the headings' order.
function column_labels( $html, $table ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$x    = new DOMXPath( $doc );
	$base = '//table[contains(concat(" ", normalize-space(@class), " "), " ' . $table . ' ")]';
	$head = array();
	foreach ( $x->query( $base . '/thead//th' ) as $th ) {
		$head[] = trim( $th->textContent );
	}
	$rows = array();
	foreach ( $x->query( $base . '/tbody/tr' ) as $tr ) {
		$row = array();
		foreach ( $x->query( 'td', $tr ) as $td ) {
			$row[] = $td->getAttribute( 'data-label' );
		}
		$rows[] = $row;
	}
	return array( $head, $rows );
}
$_GET = array();
ob_start();
include dirname( __DIR__ ) . '/admin/views/template-list.php';
list( $head, $rows ) = column_labels( ob_get_clean(), 'tc-templates-table' );
check( 'the templates table: every cell labelled with its column\'s heading, for narrow screens', array( count( $head ), count( $rows ) > 1, array_unique( array_map( 'serialize', $rows ) ) ), array( 7, true, array( serialize( $head ) ) ) );
ob_start();
include dirname( __DIR__ ) . '/admin/views/template-settings.php';
list( $head, $rows ) = column_labels( ob_get_clean(), 'tc-category-map' );
check( '  and the category rules, the row "Add a row" copies included', array( $head, array_unique( array_map( 'serialize', $rows ) ), count( $rows ) >= 3 ), array( array( 'Category', 'Template', 'Priority' ), array( serialize( $head ) ), true ) );

$sources = '';
foreach ( glob( dirname( __DIR__ ) . '/admin/views/template*.php' ) as $f ) {
	$sources .= file_get_contents( $f );
}
$sources .= file_get_contents( dirname( __DIR__ ) . '/admin/assets/js/templates.js' );
check( 'nothing on the template screens allows scripts in the frame', false === stripos( $sources, 'allow-scripts' ), true );
check( 'the script puts server text on the page as text, never markup', array( false === strpos( $sources, '.html(' ), false === strpos( $sources, 'innerHTML' ) ), array( true, true ) );
$js = file_get_contents( dirname( __DIR__ ) . '/admin/assets/js/templates.js' );
check( 'the convert form\'s radio follows input on the Convert view, above the preview\'s early return (N1)', strpos( $js, ".tc-convert-form' ).on(" ) < strpos( $js, 'if ( ! panel.length )' ), true );
check( 'a picture chosen counts as a change to the form; no confirm when the site is not connected (B5, B6)', array( false !== strpos( $js, ".val( parseInt( chosen.id, 10 ) || 0 ).trigger( 'change' )" ), false !== strpos( $js, 'cfg.connected && ! window.confirm(' ), false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-template-admin.php' ), "'connected'  => TCGiant_Sync_OAuth::instance()->is_authenticated()," ) ), array( true, true, true ) );

run( function () use ( $admin, $evil ) { $_POST = array( 'product_id' => 101, 'template_id' => $evil ); $admin->ajax_preview(); } );
$answer = run( function () use ( $admin, $evil ) { $_POST = array( 'product_id' => 101, 'template_id' => $evil ); $admin->ajax_preview(); } );
check( 'the preview sends the cleaned bytes', array( $answer[0], false === stripos( (string) ( $answer[1]['html'] ?? '<script' ), '<script' ) ), array( 'json_ok', true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nCHECK WITH EBAY: ONE CALL A PRESS, CAPPED A DAY\n" . str_repeat( '=', 124 ) . "\n";

TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'verify_daily_cap' => 2 ) );
$GLOBALS['API']->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Success' );
$presses = array();
for ( $i = 0; $i < 3; $i++ ) {
	$GLOBALS['API']->calls = array();
	$r         = run( $handlers['check'] );
	$presses[] = array( $r[0], count( $GLOBALS['API']->calls ) );
}
check( 'two presses allowed, one call each; the third refused with no call', $presses, array( array( 'json_ok', 1 ), array( 'json_ok', 1 ), array( 'json_error', 0 ) ) );
check( '  counted by the UTC day, as eBay calls are', get_transient( TCGiant_Sync_Templates::VERIFY_TRANSIENT . gmdate( 'Y-m-d' ) ), 2 );
check( '  and the screen says none are left', TCGiant_Sync_Template_Admin::checks_left(), 0 );

// Only eBay's own answer is "eBay would refuse", and only a call made
// counts (browser review, M1).
delete_transient( TCGiant_Sync_Templates::VERIFY_TRANSIENT . gmdate( 'Y-m-d' ) );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'verify_daily_cap' => 5 ) );
$press = function () use ( $handlers ) {
	$GLOBALS['API']->calls = array();
	$r = run( $handlers['check'] );
	return array( $r[0], (string) ( $r[1]['message'] ?? '' ), count( $GLOBALS['API']->calls ), TCGiant_Sync_Template_Admin::checks_left() );
};
TCGiant_Sync_OAuth::$connected = false;
$r = $press();
check( 'not connected: said so, nothing asked of eBay, nothing counted', array( $r[0], false !== strpos( $r[1], 'not connected to eBay' ), $r[2], $r[3] ), array( 'json_error', true, 0, 5 ) );
TCGiant_Sync_OAuth::$connected = true;
TCGiant_Sync_OAuth::$settings['export_category_id'] = '';
$r = $press();
check( 'a setting missing: the check says which, not "push", and counts nothing', array( $r[0], false !== strpos( $r[1], 'Check with eBay needs these settings first' ), false === strpos( $r[1], 'push' ), $r[2], $r[3] ), array( 'json_error', true, true, 0, 5 ) );
TCGiant_Sync_OAuth::$settings['export_category_id'] = '29223';
$GLOBALS['API']->answers['VerifyAddFixedPriceItem'] = new WP_Error( 'api_error', 'The title is too long.' );
$r = $press();
check( 'eBay\'s own refusal: "eBay would refuse", and a check used', array( $r[1], $r[2], $r[3] ), array( 'eBay would refuse this listing: The title is too long.', 1, 4 ) );
$GLOBALS['API']->answers['VerifyAddFixedPriceItem'] = new WP_Error( 'no_token', 'No valid eBay token.' );
$r = $press();
check( 'the token refused: the connection is named, not the listing, and nothing counted', array( false !== strpos( $r[1], 'did not accept this site\'s connection' ), false === strpos( $r[1], 'would refuse' ), $r[3] ), array( true, true, 4 ) );
$GLOBALS['API']->answers['VerifyAddFixedPriceItem'] = new WP_Error( 'rate_limited', 'Daily API call budget exhausted.', array( 'daily_budget' => 1 ) );
$r = $press();
check( 'the day\'s call budget spent: no answer from eBay, nothing counted', array( false !== strpos( $r[1], 'did not get an answer from eBay' ), $r[3] ), array( true, 4 ) );
$GLOBALS['API']->answers['VerifyAddFixedPriceItem'] = array( 'Ack' => 'Success' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTEMPLATE SETTINGS: WHAT A FIELD CANNOT HOLD IS SAID, AND KEEPS WHAT WAS THERE\n" . str_repeat( '=', 124 ) . "\n";

$said = function () {
	$n = get_transient( 'tcgiant_tpl_notice_1' );
	delete_transient( 'tcgiant_tpl_notice_1' );
	return is_array( $n ) ? $n : array( 'type' => '', 'text' => '' );
};
$settings_save = function ( array $post ) use ( $admin ) {
	$_POST = $post;
	run( function () use ( $admin ) { $admin->handle_settings(); } );
};
TCGiant_Sync_Exporter::set_bulk_push_cap( 20000 );
$said();
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7' ), 'bulk_push_cap' => '' ) );
check( 'an empty bulk-push limit keeps the limit (push-path L4)', array( TCGiant_Sync_Exporter::bulk_push_cap(), $said()['type'] ), array( 20000, 'success' ) );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7' ), 'bulk_push_cap' => 'abc' ) );
$n = $said();
check( 'text in it keeps the limit too, and says so (browser review, M2)', array( TCGiant_Sync_Exporter::bulk_push_cap(), $n['type'], false !== strpos( $n['text'], 'must be a whole number' ) ), array( 20000, 'warning', true ) );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7' ), 'bulk_push_cap' => '49' ) );
$n = $said();
check( 'a limit under 50 is set to 50, and said', array( TCGiant_Sync_Exporter::bulk_push_cap(), false !== strpos( $n['text'], '49 was set to 50' ) ), array( 50, true ) );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7' ), 'bulk_push_cap' => '20001' ) );
check( '  and one over 20,000 to 20,000', array( TCGiant_Sync_Exporter::bulk_push_cap(), false !== strpos( $said()['text'], '20001 was set to 20,000' ) ), array( 20000, true ) );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '999' ) ) );
check( 'a Check with eBay limit over 200 is set to 200, and said (browser round 2, B3)', array( TCGiant_Sync_Templates::settings()['verify_daily_cap'], false !== strpos( $said()['text'], '999 was set to 200' ) ), array( 200, true ) );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7' ) ) );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '' ) ) );
check( 'an empty Check with eBay limit keeps the one there, rather than turning the button off', TCGiant_Sync_Templates::settings()['verify_daily_cap'], 7 );
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7', 'meta_allowlist' => "bad key!\ncard_year, _cost_price\nset:name" ) ) );
$n = $said();
check( 'the meta allowlist: one key a line or between commas, a space no separator', TCGiant_Sync_Templates::settings()['meta_allowlist'], array( 'card_year', '_cost_price', 'set:name' ) );
check( '  "bad key!" refused, and said; the private key warned of', array( false !== strpos( $n['text'], '"bad key!" is not a meta key' ), false !== strpos( $n['text'], '_cost_price is a private key' ) ), array( true, true ) );

// A site not served over https: its images are kept, left out when sent, and that is said (browser review, L1).
$GLOBALS['W']['images'][801] = 'http://shop.example/logo.png';
$GLOBALS['W']['home']        = 'http://shop.example';
$settings_save( array( 'tpl' => array( 'verify_daily_cap' => '7', 'logo_id' => '801' ) ) );
check( 'on a site without https the shop logo is kept, and the notice says the pictures are left out', array( TCGiant_Sync_Templates::settings()['logo_id'], false !== strpos( $said()['text'], 'not served over https' ) ), array( 801, true ) );
$_GET = array();
ob_start();
include dirname( __DIR__ ) . '/admin/views/template-settings.php';
$settings_page = ob_get_clean();
check( '  the form still shows the logo chosen, though a listing leaves it out (B4)', false !== strpos( $settings_page, 'src="http://shop.example/logo.png"' ), true );
check( '  and the preview says so too', 1 === count( array_filter( described( new WC_Product( 103, array( 'name' => 'X', 'description' => 'y' ) ), array( 'template_id' => $tpl ) )['warnings'], function ( $w ) { return false !== strpos( $w, 'not served over https' ); } ) ), true );
$GLOBALS['W']['home'] = 'https://shop.example';

// Saved as Draft but kept Published: said why (converter review, L9a).
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'default_template_id' => $tpl ) );
$said();
run( function () use ( $admin, $tpl ) { $_POST = array( 'template_id' => $tpl, 'name' => 'Clean', 'html' => '<div>{{{description}}}</div>', 'status' => 'draft' ); $admin->handle_save(); } );
$n = $said();
check( 'a template in use set to Draft stays Published, and the notice says why', array( TCGiant_Sync_Templates::get( $tpl )['status'], $n['type'], false !== strpos( $n['text'], 'It stays Published' ) ), array( 'publish', 'warning', true ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PRODUCTS LIST\n" . str_repeat( '=', 124 ) . "\n";

// Its bulk notice names a template only to someone who can set one (security, 6).
$secret = make_template( array( 'name' => 'Secret draft', 'status' => 'draft' ) );
$notice = function () use ( $admin, $secret ) {
	$_REQUEST = array( 'tcgiant_tpl_set' => '0', 'tcgiant_tpl_name' => (string) $secret );
	ob_start();
	$admin->bulk_notice();
	return ob_get_clean();
};
$GLOBALS['W']['caps']['edit_products'] = false;
check( 'the bulk notice says nothing to a user who cannot edit products', $notice(), '' );
$GLOBALS['W']['caps']['edit_products'] = true;
check( '  and names the template to one who can', false !== strpos( $notice(), 'Secret draft' ), true );
$_REQUEST = array();

// WordPress prints bulk-action labels as they are (security, hardening 1).
$labelled = make_template( array( 'name' => 'Fish & "Chips"' ) );
$labels   = $admin->register_bulk_actions( array() );
check( 'a template\'s name is escaped in its bulk action', $labels[ TCGiant_Sync_Template_Admin::BULK_PREFIX . $labelled ] ?? '', 'eBay template: Fish &amp; &quot;Chips&quot;' );

done();
