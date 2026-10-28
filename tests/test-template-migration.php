<?php
/**
 * Harness: with no template, a push sends exactly what 3.27.7 sent.
 *
 * "Nothing changes until you pick one" is the promise listing templates ship
 * on. Every shop arrives at 3.27.8 with None as its default, no category
 * rules and no product set to a template, and for all of them the
 * description must be byte for byte what 3.27.7's build_description() made -
 * for each of its four fallbacks: the long description, the short one, the
 * attributes, and the name.
 *
 * 3.27.7's build_description() is copied below, verbatim, and the real one in
 * this tree is read and compared against that copy, so a change to it fails
 * here rather than drifting quietly away from the copy.
 *
 * Loaded for real: the exporter, the template classes, WordPress's filters
 * (tests/wordpress/plugin.php) and the admin class's settings sanitiser.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

/** 3.27.7's TCGiant_Sync_Exporter::build_description(), verbatim. */
function description_3277( WC_Product $product ) {
		$desc = $product->get_description();

		if ( empty( trim( $desc ) ) ) {
			$desc = $product->get_short_description();
		}

		if ( empty( trim( $desc ) ) ) {
			// Auto-generate a minimal description from product attributes.
			$attrs = $product->get_attributes();
			if ( ! empty( $attrs ) ) {
				$lines = array();
				foreach ( $attrs as $attr ) {
					$name  = wc_attribute_label( $attr->get_name() );
					$value = implode( ', ', $attr->get_options() );
					if ( $name && $value ) {
						$lines[] = '<strong>' . esc_html( $name ) . ':</strong> ' . esc_html( $value );
					}
				}
				if ( ! empty( $lines ) ) {
					$desc = implode( '<br>', $lines );
				}
			}
		}

		if ( empty( trim( $desc ) ) ) {
			$desc = esc_html( $product->get_name() );
		}

		// Apply WP content filters (shortcodes, etc.) for a clean HTML output.
		return apply_filters( 'the_content', $desc );
}

// the_content as a shop might have it: WordPress's paragraphs, played, and a
// shortcode another plugin expands. Both paths run the same filter.
add_filter( 'the_content', function ( $text ) {
	return '<p>' . str_replace( '[year]', '2026', $text ) . "</p>\n";
} );

$GLOBALS['W']['term_names']['pa_set|31'] = 'Base Set';
$GLOBALS['W']['term_names']['pa_set|32'] = 'Jungle';

$fixtures = array(
	'long description only'  => new WC_Product( 11, array( 'name' => 'Charizard 4/102', 'description' => "Holo rare. Light play, <em>no</em> creases & a sharp corner, [year].\n\nShips in a top loader." ) ),
	'short description only' => new WC_Product( 12, array( 'name' => 'Blastoise 2/102', 'short_description' => 'Near mint; "shadowless".' ) ),
	'attributes only'        => new WC_Product( 13, array( 'name' => 'Venusaur 15/102', 'attributes' => array(
		new WC_Product_Attribute( 'pa_set', array( 31, 32 ), true ),
		new WC_Product_Attribute( 'Card Number', array( '15/102' ) ),
		new WC_Product_Attribute( 'Language', array() ),
	) ) ),
	'name only'              => new WC_Product( 14, array( 'name' => 'Pikachu <Red Cheeks> & friends' ) ),
);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REAL build_description() IS STILL 3.27.7'S\n" . str_repeat( '=', 124 ) . "\n";

$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-exporter.php' );
preg_match( '/private function build_description\( WC_Product \$product \) \{\n(.*?)\n\t\}\n/s', $src, $m );
$mine = file_get_contents( __FILE__ );
preg_match( '/function description_3277\( WC_Product \$product \) \{\n(.*?)\n\}\n/s', $mine, $copy );
check( 'its body is the copy above, line for line', isset( $m[1], $copy[1] ) && $m[1] === $copy[1], true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nNO TEMPLATE: BYTE FOR BYTE WHAT 3.27.7 SENT\n" . str_repeat( '=', 124 ) . "\n";

$build = private_method( 'TCGiant_Sync_Exporter', 'build_description' );
foreach ( $fixtures as $label => $product ) {
	$sent = described( $product );
	check( $label . ': the description', $sent['html'], description_3277( $product ) );
	check( '  sent as None, with no template, and the size counted', array( $sent['none'], $sent['template_id'], $sent['chars'] ), array( true, 0, mb_strlen( description_3277( $product ), 'UTF-8' ) ) );
	check( '  and the item\'s Description holds those bytes, split at ]]> as before', false !== strpos( item_xml( $product, push_settings( $product ) ), '<Description><![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', description_3277( $product ) ) . ']]></Description>' ), true );
}
check( 'attributes only: 3.27.7\'s term IDs, kept on the None path (fix e is not in this release)', false !== strpos( described( $fixtures['attributes only'] )['html'], '31, 32' ), true );

$cdata = new WC_Product( 15, array( 'name' => 'Code', 'description' => 'if ( a[b[0]]>c ) { }' ) );
check( 'a "]]>" in the description is still split at the CDATA wrap', false !== strpos( item_xml( $cdata, push_settings( $cdata ) ), ']]]]><![CDATA[>' ), true );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY WAY OF HAVING NO TEMPLATE\n" . str_repeat( '=', 124 ) . "\n";

$clean = make_template( array( 'name' => 'Clean', 'html' => '<div>{{{description}}}</div>' ) );
$long  = $fixtures['long description only'];
$want  = description_3277( $long );

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'default_template_id' => 0 ) );
check( 'templates exist but none is chosen: unchanged', described( $long )['html'], $want );

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'default_template_id' => $clean ) );
update_post_meta( 11, TCGiant_Sync_Templates::OVERRIDE_META, 'none' );
check( 'a default, and the product set to None: unchanged', described( $long )['html'], $want );
delete_post_meta( 11, TCGiant_Sync_Templates::OVERRIDE_META );
check( '  and with the product following the rules, the template is used', described( $long )['none'], false );

$GLOBALS['W']['posts'][ $clean ]->post_status = 'draft';
$GLOBALS['W']['log'] = array();
check( 'the default put back to draft: unchanged', described( $long )['html'], $want );
check( '  and the log says why, once', count( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'has been deleted or is a draft' ); } ) ), 1 );
$GLOBALS['W']['posts'][ $clean ]->post_status = 'publish';

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'default_template_id' => 999999 ) );
check( 'a default since deleted: unchanged', described( $long )['html'], $want );

update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array( 'default_template_id' => $clean ) );
update_post_meta( 11, '_ebay_site', 'Germany' );
$GLOBALS['W']['log'] = array();
check( 'a listing on another eBay site, with a default: unchanged', described( $long )['html'], $want );
check( '  and the log says why', count( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'is on eBay Germany' ); } ) ), 1 );
update_post_meta( 11, '_ebay_site', 'eBayMotors' );
check( 'eBay Motors is eBay US: the template is used', described( $long )['none'], false );
delete_post_meta( 11, '_ebay_site' );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, array() );

check( 'the new filter, with nothing on it, changes nothing', has_filter( 'tcgiant_sync_ebay_description' ), false );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSAVING THE IMPORT OR PUSH FORM LEAVES THE TEMPLATE SETTINGS ALONE\n" . str_repeat( '=', 124 ) . "\n";

function sanitize_textarea_field( $text ) { return trim( (string) $text ); }
require dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();

$kept = array( 'default_template_id' => $clean, 'category_map' => array( array( 'term_id' => 5, 'template_id' => $clean, 'priority' => 1 ) ), 'meta_allowlist' => array( 'card_year' ), 'verify_daily_cap' => 7, 'shop_name' => 'Cardboard', 'logo_id' => 0, 'ebay_store_url' => '' );
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $kept );
update_option( 'tcgiant_sync_bulk_push_cap', 900 );

// What each form posts, as options.php hands it to the sanitiser.
$import_form = array( 'import_sites' => 'primary', 'overwrite_desc' => '0', 'sync_interval' => 'hourly' );
$push_form   = array( 'export_category_id' => '261328', 'export_return_policy' => 'RP1', 'default_template_id' => '0' );
update_option( 'tcgiant_sync_ebay_settings', $admin->sanitize_settings( $import_form ) );
update_option( 'tcgiant_sync_ebay_settings', $admin->sanitize_settings( $push_form ) );
check( 'both forms saved: the template settings are as they were', get_option( TCGiant_Sync_Templates::SETTINGS_OPTION ), $kept );
check( '  and so is the bulk-push limit, in its own option', get_option( 'tcgiant_sync_bulk_push_cap' ), 900 );

$view = file_get_contents( dirname( __DIR__ ) . '/admin/views/settings.php' );
check( 'neither form on the Settings screen posts the template option', false === strpos( $view, 'tcgiant_sync_template_settings' ) && false === strpos( $view, 'tcgiant_sync_bulk_push_cap' ), true );

done();
