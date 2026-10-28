<?php
/**
 * Harness: how templates and blocks are kept.
 *
 * A template is a private post with its body in meta, saved through one path
 * for the editor, Restore, Import JSON, the WP-Lister converter and the
 * starters. Each save is a new version, the last ten are kept, and Restore
 * puts an old one back as a version of its own. A template file is
 * tcgiant-template/1 JSON, read into a draft through that same path.
 *
 * Loaded for real: the templates class and the renderer's name reader.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// ─────────────────────────────────────────────────────────────────────────────
echo "\nPRIVATE POST TYPES\n" . str_repeat( '=', 124 ) . "\n";

$registered = array();
function register_post_type( $type, $args ) {
	$GLOBALS['registered'][ $type ] = $args;
}
TCGiant_Sync_Templates::register_types();
foreach ( array( TCGiant_Sync_Templates::POST_TYPE, TCGiant_Sync_Templates::BLOCK_TYPE ) as $type ) {
	$args = $registered[ $type ] ?? array();
	check( $type . ': not public, no WordPress screens, no REST, no URLs', array( $args['public'] ?? null, $args['show_ui'] ?? null, $args['show_in_rest'] ?? null, $args['publicly_queryable'] ?? null, $args['rewrite'] ?? null ), array( false, false, false, false, false ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSAVES, VERSIONS AND RESTORE\n" . str_repeat( '=', 124 ) . "\n";

$id = TCGiant_Sync_Templates::save( 0, array( 'name' => 'Clean', 'status' => 'draft', 'html' => '<div>v1 \\ {{title}}</div><style>.a > b { content: "\\201C" }</style>', 'css' => '.x{}' ), 7 );
$t  = TCGiant_Sync_Templates::get( $id );
check( 'a new template is version 1, a draft', array( $t['version'], $t['status'], $t['name'] ), array( 1, 'draft', 'Clean' ) );
check( 'a <style> typed into the HTML is moved to the stylesheet', array( $t['html'], $t['css'] ), array( '<div>v1 \\ {{title}}</div>', ".x{}\n.a > b { content: \"\\201C\" }" ) );
check( 'backslashes survive WordPress unslashing meta', false !== strpos( $t['css'], '"\\201C"' ) && false !== strpos( $t['html'], 'v1 \\ ' ), true );
check( 'the body is not in post_content, which kses would strip', $GLOBALS['W']['posts'][ $id ]->post_content, '' );
check( 'its hash is stored', strlen( $t['hash'] ), 40 );

for ( $v = 2; $v <= 13; $v++ ) {
	TCGiant_Sync_Templates::save( $id, array( 'html' => '<div>v' . $v . '</div>' ), 7 );
}
$t = TCGiant_Sync_Templates::get( $id );
check( 'every save is a new version', $t['version'], 13 );
check( 'the last ten are kept, oldest first', array_column( $t['versions'], 'version' ), range( 3, 12 ) );
check( '  each with its body', $t['versions'][9]['html'], '<div>v12</div>' );

$r = TCGiant_Sync_Templates::restore( $id, 5, 7 );
$t = TCGiant_Sync_Templates::get( $id );
check( 'Restore saves the old body as a new version', array( $r, $t['version'], $t['html'] ), array( $id, 14, '<div>v5</div>' ) );
check( '  and v13 is kept to go back to', end( $t['versions'] )['html'], '<div>v13</div>' );
check( 'a version no longer kept cannot be restored', TCGiant_Sync_Templates::restore( $id, 1 )->get_error_code(), 'no_version' );

$big = TCGiant_Sync_Templates::save( $id, array( 'html' => str_repeat( 'x', 500001 ) ) );
check( 'a body eBay could never take is refused, and nothing changes', array( is_wp_error( $big ) ? $big->get_error_code() : $big, TCGiant_Sync_Templates::get( $id )['version'] ), array( 'template_too_long', 14 ) );

check( 'an unknown language or filter setting is made safe', array( TCGiant_Sync_Templates::get( TCGiant_Sync_Templates::save( 0, array( 'name' => 'L', 'lang' => 'xx', 'content_filters' => 'eval' ) ) )['lang'], TCGiant_Sync_Templates::get( TCGiant_Sync_Templates::save( 0, array( 'name' => 'F', 'content_filters' => 'eval' ) ) )['content_filters'] ), array( 'en', 'core' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nIN USE\n" . str_repeat( '=', 124 ) . "\n";

TCGiant_Sync_Templates::save( $id, array( 'status' => 'publish' ) );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'default_template_id' => $id ) );
check( 'deleting the default is refused', TCGiant_Sync_Templates::delete( $id )->get_error_code(), 'assigned' );
TCGiant_Sync_Templates::save( $id, array( 'status' => 'draft' ) );
check( 'a template something is set to stays published', TCGiant_Sync_Templates::get( $id )['status'], 'publish' );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array() );

$copy = TCGiant_Sync_Templates::duplicate( $id, 7 );
check( 'Duplicate makes a draft copy, version 1', array( TCGiant_Sync_Templates::get( $copy )['name'], TCGiant_Sync_Templates::get( $copy )['status'], TCGiant_Sync_Templates::get( $copy )['version'], TCGiant_Sync_Templates::get( $copy )['html'] ), array( 'Clean (copy)', 'draft', 1, '<div>v5</div>' ) );

new WC_Product( 61, array( 'name' => 'P' ) );
update_post_meta( 61, TCGiant_Sync_Templates::OVERRIDE_META, (string) $copy );
check( 'deleting one a product is set to is refused', TCGiant_Sync_Templates::delete( $copy )->get_error_code(), 'assigned' );
check( '  its use is counted', TCGiant_Sync_Templates::assignments( $copy ), array( 'default' => false, 'categories' => 0, 'products' => 1 ) );
delete_post_meta( 61, TCGiant_Sync_Templates::OVERRIDE_META );
check( 'and one nothing is set to is deleted', array( TCGiant_Sync_Templates::delete( $copy ), TCGiant_Sync_Templates::get( $copy ) ), array( true, null ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBLOCKS\n" . str_repeat( '=', 124 ) . "\n";

$tpl = TCGiant_Sync_Templates::save( 0, array( 'name' => 'With block', 'html' => '<div>{{{block.about-us}}}</div>' ) );
$h1  = TCGiant_Sync_Templates::get( $tpl )['hash'];
TCGiant_Sync_Templates::save_block( 'About us', 'About us', array( 'en' => '<p>We grade every card.</p>', 'de' => '<p>Wir bewerten jede Karte.</p>', 'xx' => 'dropped' ) );
$block = TCGiant_Sync_Templates::get_block( 'about-us' );
check( 'a block is kept by its slug, per language', array( $block['slug'], array_keys( $block['html'] ), $block['version'] ), array( 'about-us', array( 'en', 'de' ), 1 ) );
$h2 = TCGiant_Sync_Templates::get( $tpl )['hash'];
TCGiant_Sync_Templates::save_block( 'about-us', 'About us', array( 'en' => '<p>We grade every card twice.</p>' ) );
$h3 = TCGiant_Sync_Templates::get( $tpl )['hash'];
check( 'saving a block changes the hash of every template that uses it', array( $h1 !== $h2, $h2 !== $h3, TCGiant_Sync_Templates::get_block( 'about-us' )['version'] ), array( true, true, 2 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTEMPLATE FILES (tcgiant-template/1)\n" . str_repeat( '=', 124 ) . "\n";

$GLOBALS['W']['images'][701] = 'https://shop.example/logo.png';
TCGiant_Sync_Templates::save( $tpl, array( 'css' => '.a > b {}', 'design' => array( 'accent' => '#336699', 'logo_id' => 701 ) ) );
$json = TCGiant_Sync_Templates::export_json( $tpl );
$file = json_decode( $json, true );
check( 'the file says what it is', $file['format'], 'tcgiant-template/1' );
check( '  it carries name, language, HTML and CSS', array( $file['name'], $file['lang'], $file['html'], $file['css'] ), array( 'With block', 'en', '<div>{{{block.about-us}}}</div>', '.a > b {}' ) );
check( '  and the blocks it uses, in its language', $file['blocks'], array( array( 'slug' => 'about-us', 'kind' => 'text', 'html' => '<p>We grade every card twice.</p>' ) ) );
check( '  but not this site\'s images, which mean nothing elsewhere', array( $file['design']['logo_id'], $file['design']['accent'] ), array( 0, '#336699' ) );

TCGiant_Sync_Templates::delete_block( 'about-us' );
$in = TCGiant_Sync_Templates::import_json( $json, 7 );
$imported = TCGiant_Sync_Templates::get( $in['id'] );
check( 'imported as a new draft, through the same save path', array( $imported['status'], $imported['version'], $imported['html'], $imported['css'] ), array( 'draft', 1, '<div>{{{block.about-us}}}</div>', '.a > b {}' ) );
check( '  its block added, being missing here', array( TCGiant_Sync_Templates::get_block( 'about-us' )['html']['en'] ?? null, count( $in['notes'] ) ), array( '<p>We grade every card twice.</p>', 1 ) );
TCGiant_Sync_Templates::save_block( 'about-us', 'About us', array( 'en' => '<p>Ours.</p>' ) );
$again = TCGiant_Sync_Templates::import_json( $json, 7 );
check( 'a block this site already has is kept as it is, and that is said', array( TCGiant_Sync_Templates::get_block( 'about-us' )['html']['en'], false !== strpos( $again['notes'][0], 'already exists' ) ), array( '<p>Ours.</p>', true ) );

$styled = json_encode( array( 'format' => 'tcgiant-template/1', 'name' => 'S', 'html' => '<p>x</p><style>p{color:red}</style>' ) );
check( 'an import\'s <style> in the HTML is moved to the CSS, as a save does', TCGiant_Sync_Templates::get( TCGiant_Sync_Templates::import_json( $styled )['id'] )['css'], 'p{color:red}' );
check( 'a file of another format is refused', TCGiant_Sync_Templates::import_json( json_encode( array( 'format' => 'wplister', 'name' => 'x', 'html' => 'x' ) ) )->get_error_code(), 'not_a_template' );
check( 'one that is not JSON is refused', TCGiant_Sync_Templates::import_json( '<?php system("x");' )->get_error_code(), 'not_a_template' );
check( 'one with no HTML is refused', TCGiant_Sync_Templates::import_json( json_encode( array( 'format' => 'tcgiant-template/1', 'name' => 'x' ) ) )->get_error_code(), 'not_a_template' );
check( 'one over 2 MB is refused before it is read', TCGiant_Sync_Templates::import_json( str_repeat( ' ', 2097153 ) )->get_error_code(), 'too_large' );

// One name, one template (browser review, L6).
$first  = make_template( array( 'name' => 'Same name' ) );
$second = make_template( array( 'name' => 'Same name' ) );
$third  = make_template( array( 'name' => 'same NAME' ) );
check( 'a second template of the same name gets " (2)", a third " (3)", whatever the case', array( TCGiant_Sync_Templates::get( $second )['name'], TCGiant_Sync_Templates::get( $third )['name'] ), array( 'Same name (2)', 'same NAME (3)' ) );
TCGiant_Sync_Templates::save( $first, array( 'name' => 'Same name', 'html' => 'v2' ), 1 );
check( '  saving the first again keeps its own name', TCGiant_Sync_Templates::get( $first )['name'], 'Same name' );
$copy_of_second = make_template( array( 'name' => 'Same name (2)' ) );
check( '  a copy of "Same name (2)" is "Same name (4)", never "Same name (2) (2)" (browser round 2, B2)', TCGiant_Sync_Templates::get( $copy_of_second )['name'], 'Same name (4)' );
$imported_same = TCGiant_Sync_Templates::import_json( json_encode( array( 'format' => 'tcgiant-template/1', 'name' => 'Same name', 'html' => '<p>x</p>' ) ), 1 );
check( '  an import of that name too', TCGiant_Sync_Templates::get( is_array( $imported_same ) ? (int) ( $imported_same['id'] ?? 0 ) : (int) $imported_same )['name'] ?? '', 'Same name (5)' );

// The after-send records belong to the listing, not the product: a
// Duplicate or an Unlink drops them; the template choice is a setting and
// is copied (push-path L6).
require_once dirname( __DIR__ ) . '/includes/class-tcgiant-sync-listing-link.php';
$identity = TCGiant_Sync_Listing_Link::listing_identity_meta_keys();
check( 'every after-send key is among the listing\'s identity keys', array_values( array_diff( TCGiant_Sync_Templates::SENT_META, $identity ) ), array() );
check( '  the product\'s template choice and eBay-only description are not', array( in_array( TCGiant_Sync_Templates::OVERRIDE_META, $identity, true ), in_array( TCGiant_Sync_Templates::EBAY_DESCRIPTION_META, $identity, true ) ), array( false, false ) );

done();
