<?php
/**
 * Harness: the Settings screen never puts the eBay tokens in the page.
 *
 * Both Settings forms post to options.php, and WordPress replaces the whole
 * tcgiant_sync_ebay_settings option with what a form sends, so until 3.27.8
 * each form carried every stored key back in hidden fields - the access
 * token, the 18-month refresh token, the relay's key for this site and an
 * old eBay Cert ID among them, there in the page for anything that reads it.
 * Now the forms carry a marker instead of those four, and a save from a form
 * keeps them as stored; one posted is ignored. Every other write of the
 * option - the connection, a token refresh, Disconnect - carries no marker
 * and is kept exactly as written.
 *
 * Loaded for real: the Settings view, TCGiant_Sync_Admin::sanitize_settings()
 * and its constants, and the OAuth class's token refresh.
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/template-world.php';

// --- what the Settings view needs, played --------------------------------------

function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function selected( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function checked( $a, $b = true, $echo = true ) { $out = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">'; }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=nonce'; }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function sanitize_textarea_field( $text ) { return trim( (string) $text ); }
function get_terms( $args = array() ) { return array(); }

// The four secrets, as distinctive as a search for them can be.
$SECRETS = array(
	'access_token'  => 'v^1.1#i^1#ACCESS-TOKEN-7f3a9c',
	'refresh_token' => 'v^1.1#i^1#REFRESH-TOKEN-2b8e41',
	'relay_secret'  => 'RELAY-SECRET-5d0c77',
	'cert_id'       => 'PRD-CERT-ID-9e14aa',
);
$STORED = array_merge( $SECRETS, array(
	'token_expiry'          => '1893456000',
	'app_id'                => 'TCGiant-Sync-PRD-public',
	'marketplace'           => 'EBAY_US',
	'store_name'            => 'cardboardshop',
	'export_category_id'    => '261328',
	'export_return_policy'  => 'RP1',
	'import_sites'          => 'all',
	'overwrite_desc'        => '0',
	'sync_interval'         => 'hourly',
) );

class TCGiant_Sync_Entitlements {
	public static function instance() { return new self(); }
	public function get_status_for_ui() { return array( 'is_pro' => false, 'upgrade_url' => 'https://tcgiant.example/pro', 'variant' => 'free' ); }
	public function __call( $name, $args ) { return null; }
}

require dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php';
$admin = ( new ReflectionClass( 'TCGiant_Sync_Admin' ) )->newInstanceWithoutConstructor();
$slot  = new ReflectionProperty( 'TCGiant_Sync_Admin', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$slot->setAccessible( true );
}
$slot->setValue( null, $admin );

/** The Settings screen, as a browser gets it, for the option as stored. */
function settings_page( array $stored ) {
	TCGiant_Sync_OAuth::$settings = $stored;
	update_option( 'tcgiant_sync_ebay_settings', $stored );
	ob_start();
	include dirname( __DIR__ ) . '/admin/views/settings.php';
	return ob_get_clean();
}

/** A save from a form: what options.php hands the option's sanitiser. */
function form_save( array $posted ) {
	global $admin;
	update_option( 'tcgiant_sync_ebay_settings', $admin->sanitize_settings( $posted ) );
	return get_option( 'tcgiant_sync_ebay_settings' );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PAGE HOLDS NONE OF THEM\n" . str_repeat( '=', 124 ) . "\n";

$page = settings_page( $STORED );
check( 'the Settings screen drew both forms', substr_count( $page, 'name="option_page" value="tcgiant_sync_ebay_group"' ), 2 );
foreach ( $SECRETS as $key => $value ) {
	check( 'not in the page: ' . $key, array( false === strpos( $page, $value ), false === strpos( $page, esc_attr( $value ) ), false === strpos( $page, 'tcgiant_sync_ebay_settings[' . $key . ']' ) ), array( true, true, true ) );
}
check( 'each form carries the marker instead', substr_count( $page, 'name="tcgiant_sync_ebay_settings[' . TCGiant_Sync_Admin::SETTINGS_FORM_MARKER . ']" value="1"' ), 2 );
check( 'what is not secret still goes with the forms: the App ID, the expiry, the marketplace', array( false !== strpos( $page, 'TCGiant-Sync-PRD-public' ), false !== strpos( $page, 'tcgiant_sync_ebay_settings[token_expiry]' ), false !== strpos( $page, 'tcgiant_sync_ebay_settings[marketplace]' ) ), array( true, true, true ) );
check( 'the list of secrets is the four', TCGiant_Sync_Admin::SECRET_SETTINGS, array( 'access_token', 'refresh_token', 'relay_secret', 'cert_id' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA SAVE FROM A FORM KEEPS THEM, AND CANNOT SET THEM\n" . str_repeat( '=', 124 ) . "\n";

// What the import form posts now: its own fields, what it carries, the marker.
update_option( 'tcgiant_sync_ebay_settings', $STORED );
$import_form = array( TCGiant_Sync_Admin::SETTINGS_FORM_MARKER => '1', 'import_sites' => 'all', 'overwrite_desc' => '1', 'sync_interval' => 'hourly', 'token_expiry' => '1893456000', 'app_id' => 'TCGiant-Sync-PRD-public', 'marketplace' => 'EBAY_US', 'store_name' => 'cardboardshop', 'export_category_id' => '261328', 'export_return_policy' => 'RP1' );
$saved = form_save( $import_form );
check( 'all four kept as they were stored', array_intersect_key( $saved, $SECRETS ), $SECRETS );
check( '  and every setting the form sent, as it sent it', array( $saved['import_sites'], $saved['overwrite_desc'], $saved['export_category_id'], $saved['app_id'] ), array( 'all', '1', '261328', 'TCGiant-Sync-PRD-public' ) );
check( '  the marker is not stored', array_key_exists( TCGiant_Sync_Admin::SETTINGS_FORM_MARKER, $saved ), false );

$saved = form_save( array_merge( $import_form, array( 'access_token' => 'evil', 'refresh_token' => 'evil', 'relay_secret' => 'evil', 'cert_id' => 'evil' ) ) );
check( 'a form that posts access_token=evil changes none of them', array_intersect_key( $saved, $SECRETS ), $SECRETS );

update_option( 'tcgiant_sync_ebay_settings', array( 'marketplace' => 'EBAY_US' ) );
$saved = form_save( array( TCGiant_Sync_Admin::SETTINGS_FORM_MARKER => '1', 'access_token' => 'evil', 'sync_interval' => 'hourly' ) );
check( '  nor sets one on a site that has none', array_key_exists( 'access_token', $saved ), false );
// The key is judged as it is stored, after sanitize_key() (security review, round 2).
foreach ( array( 'ACCESS_TOKEN', 'Access_Token', 'access_token ', 'acc ess_token', "access_token\0", 'Refresh_Token', 'RELAY_SECRET', 'Cert_ID' ) as $spelling ) {
	update_option( 'tcgiant_sync_ebay_settings', array( 'marketplace' => 'EBAY_US' ) );
	$saved = form_save( array( TCGiant_Sync_Admin::SETTINGS_FORM_MARKER => '1', $spelling => 'evil', 'sync_interval' => 'hourly' ) );
	check( '  nor by another spelling of the key: ' . var_export( $spelling, true ), array_values( array_intersect( array_keys( $saved ), TCGiant_Sync_Admin::SECRET_SETTINGS ) ), array() );
}
update_option( 'tcgiant_sync_ebay_settings', $STORED );
$saved = form_save( array_merge( $import_form, array( 'ACCESS_TOKEN' => 'evil', 'access_token ' => 'evil' ) ) );
check( '  and on a connected site, the stored token stands', $saved['access_token'], $SECRETS['access_token'] );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nEVERY OTHER WRITE IS KEPT EXACTLY AS WRITTEN\n" . str_repeat( '=', 124 ) . "\n";

// Disconnect writes the option without the tokens, and no marker.
update_option( 'tcgiant_sync_ebay_settings', $STORED );
$disconnected = $STORED;
unset( $disconnected['access_token'], $disconnected['refresh_token'], $disconnected['token_expiry'] );
$saved = form_save( $disconnected );
check( 'Disconnect, with no marker: the tokens are gone', array( array_key_exists( 'access_token', $saved ), array_key_exists( 'refresh_token', $saved ), $saved['relay_secret'] ), array( false, false, $SECRETS['relay_secret'] ) );
$admin_src = file_get_contents( dirname( __DIR__ ) . '/admin/class-tcgiant-sync-admin.php' );
check( 'Disconnect also clears an old Cert ID, which nothing reads', false !== strpos( $admin_src, "\$settings['store_id'], \$settings['cert_id'] );" ), true );

// The token refresh: its own write, as it makes it.
update_option( 'tcgiant_sync_ebay_settings', $STORED );
$refreshed = array_merge( $STORED, array( 'access_token' => 'v^1.1#i^1#NEW-ACCESS-TOKEN', 'token_expiry' => '1893463200' ) );
$saved     = form_save( $refreshed );
check( 'a token refresh stores the new access token', array( $saved['access_token'], $saved['token_expiry'] ), array( 'v^1.1#i^1#NEW-ACCESS-TOKEN', '1893463200' ) );
$oauth = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-oauth.php' );
check( '  which is how the OAuth class writes it: the whole option, no marker', array( false !== strpos( $oauth, "\$settings['access_token'] = \$body['access_token'];" ), false === strpos( $oauth, TCGiant_Sync_Admin::SETTINGS_FORM_MARKER ) ), array( true, true ) );

done();
