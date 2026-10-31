<?php
/**
 * Render the real Settings view, in a WordPress of its own (settings-world.php),
 * for a shop saved with a retired category ID as its default and in its
 * import filter, and print the page as JSON - for test-category-ids.php,
 * whose own world is template-world.php. A process of its own.
 *
 * usage: php tests/support/category-settings-run.php <plain|custom>
 *   custom: the shop also typed 180006 as one of its own custom categories.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/settings-world.php';

function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=nonce'; }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function sanitize_textarea_field( $text ) { return trim( (string) $text ); }
function get_terms( $args = array() ) { return array(); }

class TCGiant_Sync_Entitlements {
	public static function instance() { return new self(); }
	public function get_status_for_ui() { return array( 'is_pro' => false, 'upgrade_url' => 'https://tcgiant.example/pro', 'variant' => 'free' ); }
	public function __call( $name, $args ) { return null; }
}

require dirname( __DIR__, 2 ) . '/admin/class-tcgiant-sync-admin.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
$slot  = new ReflectionProperty( 'TCGiant_Sync_Admin', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, $admin );

$stored = array(
	'marketplace'                  => 'EBAY_US',
	'export_category_id'           => '180006',
	'export_category_name'         => '',
	'import_standard_category_ids' => array( '183454', '69243', '261068' ),
);
if ( 'custom' === ( $argv[1] ?? '' ) ) {
	$stored['custom_saved_categories'] = '180006: My desiccant packs';
}
if ( 'gb' === ( $argv[1] ?? '' ) ) {
	$stored['marketplace'] = 'EBAY_GB';
}
if ( 'sports' === ( $argv[1] ?? '' ) ) {
	$stored['export_category_id'] = '2536';
}
if ( 'typed' === ( $argv[1] ?? '' ) ) {
	$stored['export_category_typed'] = '180006';
}
TCGiant_Sync_OAuth::$settings  = $stored;
TCGiant_Sync_OAuth::$connected = true; // The Push to eBay form is drawn for a connected shop.
update_option( 'tcgiant_sync_ebay_settings', $stored );

ob_start();
include dirname( __DIR__, 2 ) . '/admin/views/settings.php';
echo json_encode( array( 'page' => ob_get_clean() ) );

// Rendered to the end: settings-world.php's shutdown check is for harnesses.
$GLOBALS['harness_done'] = true;
