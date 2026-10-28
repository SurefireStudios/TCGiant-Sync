<?php
/**
 * Harness: a staging copy of a shop must not change anything on eBay.
 *
 * A copy of a shop carries the live shop's eBay connection with it. Until
 * 3.28.0 the only thing standing between a copy and the seller's real listings
 * was a test of the web request's host name against seven substrings, and it
 * was wrong both ways round:
 *
 * - staging.numismax.com, staging1.numismax.com, dev.example.com and Kinsta's
 *   stg- addresses were judged live, and so was every WP-CLI and system-cron
 *   run, which has no host name at all. Those copies ended live listings,
 *   changed live quantities and marked real buyers' orders shipped.
 * - webdev-supplies.com and shop.localfoo.com.au, both live shops, were judged
 *   copies and sent nothing to eBay, ever.
 * - Only Trading API calls on a list were stopped. The Inventory API quantity
 *   update is a REST PUT, and went out from every copy regardless.
 * - On a copy, a stock-0 push marked the listing Ended and settled in the
 *   copy's database even though EndItem had been refused - and hosts that push
 *   a staging database to live carried those false marks over, where the live
 *   shop then skipped EndItem for a coin that had sold.
 *
 * Loaded for real: TCGiant_Sync_Environment, and the code it guards -
 * TCGiant_Sync_API, TCGiant_Sync_Inventory, TCGiant_Sync_Cron,
 * TCGiant_Sync_Jobs, TCGiant_Sync_Orders, TCGiant_Sync_Exporter and
 * TCGiant_Sync_Admin - with WordPress stubbed and only the HTTP call captured.
 * Nothing leaves the machine. The plugin decides once per request, as
 * tcgiant-sync.php loads, so each site below is a PHP process of its own, and
 * the verdict for "WP-CLI" and "web" comes from the real tcgiant-sync.php.
 *
 * The 3.27 check is run against the same addresses, through the real 3.27.2
 * file where git can supply it, so these tests are known to catch what it got
 * wrong.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-84s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$ROOT  = str_replace( '\\', '/', dirname( __DIR__ ) );
$WORLD = str_replace( '\\', '/', sys_get_temp_dir() ) . '/tcg-staging-detect-' . getmypid();

if ( ! is_dir( $WORLD ) ) {
	mkdir( $WORLD, 0777, true );
}

// --- the parent: the address rules, worked out in this process ----------------
//
// Nothing here calls boot(), so TCGIANT_SYNC_IS_STAGING is never defined in
// this process and copy_reason() can be asked about one site after another.
define( 'ABSPATH', __DIR__ . '/' );

$HOME_URL = 'https://numismax.com';
$ENV_TYPE = 'production';
$OPTIONS  = array();

function __( $text ) { return $text; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_get_environment_type() { return $GLOBALS['ENV_TYPE']; }
function wp_load_alloptions() { return array( 'home' => $GLOBALS['HOME_URL'], 'siteurl' => $GLOBALS['HOME_URL'] ); }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default; }

require $ROOT . '/includes/class-tcgiant-sync-environment.php';

/** The rule copy_rule() names for an address, or '' for live. */
function rule( $url ) { return TCGiant_Sync_Environment::copy_rule( $url ); }

/** copy_reason() for a site at this address, in this environment. */
function reason_for( $url, $env = 'production', array $options = array() ) {
	$GLOBALS['HOME_URL'] = $url;
	$GLOBALS['ENV_TYPE'] = $env;
	$GLOBALS['OPTIONS']  = $options;
	return TCGiant_Sync_Environment::copy_reason();
}

/** The host as a web request would carry it: no scheme, no path. */
function http_host_of( $url ) {
	$p = parse_url( $url );
	return strtolower( ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );
}

/** What 3.27 decided, through the copy of its check kept for the upgrade notice. */
function legacy( $http_host, $env = 'production' ) {
	$GLOBALS['ENV_TYPE'] = $env;
	if ( null === $http_host ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $http_host;
	}
	$m = new ReflectionMethod( 'TCGiant_Sync_Environment', 'legacy_verdict' );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	$verdict = $m->invoke( null );
	unset( $_SERVER['HTTP_HOST'] );
	$GLOBALS['ENV_TYPE'] = 'production';
	return $verdict;
}

// Copies, each with the rule that should catch it.
$COPIES = array(
	'https://staging.numismax.com'                  => 'label:staging',     // cPanel / Plesk WP Toolkit, SiteGround
	'https://staging1.numismax.com'                 => 'label:staging',     // SiteGround's second copy
	'https://www.staging2.example.co.uk'            => 'label:staging',
	'https://dev.example.com'                       => 'label:dev',
	'https://test.example.com'                      => 'label:test',
	'https://stage.example.com'                     => 'label:stage',
	'https://stg.example.com.au'                    => 'label:stg',
	'https://uat.shop.example.com'                  => 'label:uat',
	'https://stg-numismax-live.kinsta.cloud'        => 'prefix:stg',        // Kinsta standard staging
	'https://env-numismax-env.kinsta.cloud'         => 'kinsta:env',        // Kinsta premium staging
	'https://staging-numismax.kinsta.cloud'         => 'prefix:staging',    // Kinsta, older
	'https://staging-ab12-jonsite.wpcomstaging.com' => 'prefix:staging',    // WordPress.com staging
	'https://numismax-stg.wpenginepowered.com'      => 'suffix:stg',        // WP Engine
	'https://numismaxstg.wpengine.com'              => 'wpengine:stg',      // WP Engine, older
	'https://test-numismax.pantheonsite.io'         => 'prefix:test',       // Pantheon Test
	'https://dev-numismax.pantheonsite.io'          => 'prefix:dev',        // Pantheon Dev
	'https://numismax.local'                        => 'tld:local',         // Local
	'http://localhost:8080'                         => 'localhost',
	'http://127.0.0.1'                              => 'private-ip',
	'http://192.168.1.20/shop'                      => 'private-ip',
	'http://[::1]:8080'                             => 'private-ip',
	'https://example.test'                          => 'tld:test',
	'https://numismax.com/staging'                  => 'path:staging',      // a copy in a folder
	'https://example.com/dev2'                      => 'path:dev',
	'https://shop-staging.example.com'              => 'suffix:staging',
	'https://numismax.ddev.site'                    => 'local-domain',      // DDEV
	'https://www.numismax.ddev.site'                => 'local-domain',
	'http://numismax.lndo.site'                     => 'local-domain',      // Lando
	'http://numismax.docksal.site'                  => 'local-domain',      // Docksal
	'http://devbox'                                 => 'single-label',      // a machine name
	'http://wordpress:8080'                         => 'single-label',      // a Docker service name
	'http://numismax.localdomain'                   => 'tld:localdomain',
	'http://shop.lan'                               => 'tld:lan',
);

// Live shops. Several of these were kept off eBay by 3.27.
$LIVES = array(
	'https://numismax.com',
	'https://www.numismax.com',
	'https://webdev-supplies.com',
	'https://www.sundev-electronics.de',
	'https://shop.localfoo.com.au',
	'https://www.localcoins.com',
	'https://dev-supplies.com',
	'https://test.co.uk',
	'https://stage.com.au',
	'https://staging.co.uk',
	'https://devon-coins.co.uk',
	'https://example.com/shop',
	'https://shop.example.com/testimonials',
	'https://numismax.wpcomstaging.com',            // WordPress.com's own address for a live site
	'https://numismax.kinsta.cloud',
	'https://numismax.wpengine.com',
	'https://wordpress-123-456.cloudwaysapps.com',
	'https://numismax.myftpupload.com',
	'https://qa.example.com',                       // qa is Qatar, not quality assurance
	'https://shop.dev',                             // .dev is a real top-level domain
	'https://stagecoach-models.com',
	'https://developer-tools.example.com',
	'https://parts.dobson.com.au',
	'https://shop.ddev.com',                        // only ddev.site is DDEV's
	'https://numismax.site',
);

echo "\nCOPIES ARE CAUGHT, EACH BY THE RULE THAT SHOULD CATCH IT\n" . str_repeat( '=', 132 ) . "\n";

foreach ( $COPIES as $url => $want ) {
	check( $url, rule( $url ), $want );
}

echo "\nLIVE SHOPS ARE LEFT ALONE\n" . str_repeat( '=', 132 ) . "\n";

foreach ( $LIVES as $url ) {
	check( $url, rule( $url ), '' );
}

echo "\nA REFUSAL IS NEVER MISTAKEN FOR AN ENDED LISTING\n" . str_repeat( '=', 132 ) . "\n";

// The stock push and the exporter decide that eBay said a listing had ended by
// looking for these in the error text. A refusal containing one would be
// recorded as an ended listing - and the stock push then never tries it again.
$forbidden = array( 'ended', 'already been closed', 'No changes allowed' );
$reasons   = array( 'wp-config', 'environment:local', 'environment:development', 'environment:staging' );
foreach ( $COPIES as $want ) {
	$reasons[] = 'address:' . $want;
}
$tainted = array();
foreach ( array_unique( $reasons ) as $r ) {
	foreach ( $forbidden as $f ) {
		if ( false !== stripos( TCGiant_Sync_Environment::message( $r ), $f ) ) {
			$tainted[] = $r;
		}
	}
}
check( 'no refusal, for any reason, says ended, already been closed or No changes allowed', $tainted, array() );

// The rule is named from the plugin's own words, never the address, so a
// shop called test-extended cannot put "ended" into the message.
$r = 'address:' . rule( 'https://test-extended.example.com' );
check( 'a copy at test-extended.example.com is caught by its test- prefix', $r, 'address:prefix:test' );
check( '  and the refusal does not carry the address into its text', false === strpos( TCGiant_Sync_Environment::message( $r ), 'extended' ), true );

echo "\nTHE SAME ADDRESS IS THE SAME ADDRESS\n" . str_repeat( '=', 132 ) . "\n";

check( 'https and http are one address', TCGiant_Sync_Environment::address( 'http://numismax.com' ), TCGiant_Sync_Environment::address( 'https://numismax.com' ) );
check( 'www and the bare domain are one address', TCGiant_Sync_Environment::address( 'https://www.Numismax.com/' ), 'numismax.com' );
check( 'port 443 is no port at all', TCGiant_Sync_Environment::address( 'https://numismax.com:443' ), 'numismax.com' );
check( 'another port is kept, and so is the folder', TCGiant_Sync_Environment::address( 'https://numismax.com:8443/Shop/' ), 'numismax.com:8443/shop' );
check( 'nothing at all is no address', TCGiant_Sync_Environment::address( '' ), '' );
check( '  and no address is not judged a copy', rule( '' ), '' );

echo "\nWORDPRESS'S OWN ENVIRONMENT TYPE\n" . str_repeat( '=', 132 ) . "\n";

check( 'local, at a live address, is a copy', reason_for( 'https://numismax.com', 'local' ), 'environment:local' );
check( 'development, at a live address, is a copy', reason_for( 'https://numismax.com', 'development' ), 'environment:development' );
check( 'staging, at a live address, is a copy', reason_for( 'https://numismax.com', 'staging' ), 'environment:staging' );
check( 'production at a live address is live', reason_for( 'https://numismax.com' ), '' );
check( 'production at a copy\'s address is a copy, by its address', reason_for( 'https://staging.numismax.com' ), 'address:label:staging' );

echo "\n\"THIS IS MY LIVE SHOP\" HOLDS FOR ONE ADDRESS ONLY\n" . str_repeat( '=', 132 ) . "\n";

$confirmed = array( TCGiant_Sync_Environment::CONFIRMED_OPTION => array(
	'hash' => hash( 'sha256', 'tcgiant-sync-live|shop-dev.example.com' ),
	'user' => 7,
	'at'   => 1760000000,
) );
check( 'shop-dev.example.com looks like a copy', reason_for( 'https://shop-dev.example.com' ), 'address:suffix:dev' );
check( '  until it is confirmed as the live shop', reason_for( 'https://shop-dev.example.com', 'production', $confirmed ), '' );
check( '  which survives a move to www and to http', array( reason_for( 'https://www.shop-dev.example.com/', 'production', $confirmed ), reason_for( 'http://shop-dev.example.com', 'production', $confirmed ) ), array( '', '' ) );
check( '  but not to another address', reason_for( 'https://copy-dev.example.com', 'production', $confirmed ), 'address:suffix:dev' );
check( '  nor into a folder', reason_for( 'https://shop-dev.example.com/staging', 'production', $confirmed ), 'address:suffix:dev' );
check( '  and it never overrules WP_ENVIRONMENT_TYPE', reason_for( 'https://shop-dev.example.com', 'staging', $confirmed ), 'environment:staging' );
check( '  the stored record does not hold the address in plain text', false === strpos( serialize( $confirmed ), 'shop-dev' ), true );

echo "\nWHAT 3.27 GOT WRONG - THE SAME ADDRESSES THROUGH ITS CHECK\n" . str_repeat( '=', 132 ) . "\n";

// The upgrade notice keeps 3.27's check, unchanged, to compare against once.
// Run here over the table: everything it gets wrong is something the rules
// above get right, so these tests would have failed on 3.27.
$old_wrong = array();
foreach ( $COPIES as $url => $want ) {
	if ( ! legacy( http_host_of( $url ) ) ) {
		$old_wrong[] = 'copy judged live: ' . $url;
	}
}
foreach ( $LIVES as $url ) {
	if ( legacy( http_host_of( $url ) ) ) {
		$old_wrong[] = 'live judged a copy: ' . $url;
	}
}
echo '  3.27 got ' . count( $old_wrong ) . " of these wrong:\n";
foreach ( $old_wrong as $line ) {
	echo '      ' . $line . "\n";
}
check( '3.27 let staging.numismax.com send to eBay', in_array( 'copy judged live: https://staging.numismax.com', $old_wrong, true ), true );
check( '3.27 let Kinsta\'s stg- copies send to eBay', in_array( 'copy judged live: https://stg-numismax-live.kinsta.cloud', $old_wrong, true ), true );
check( '3.27 kept webdev-supplies.com, a live shop, off eBay', in_array( 'live judged a copy: https://webdev-supplies.com', $old_wrong, true ), true );
check( '3.27 kept shop.localfoo.com.au, a live shop, off eBay', in_array( 'live judged a copy: https://shop.localfoo.com.au', $old_wrong, true ), true );
check( '3.27 let any copy send from WP-CLI, where there is no host name', legacy( null ), false );
check( '3.27 did honour the environment type', legacy( 'numismax.com', 'staging' ), true );

// And through the real 3.27.2 file, where the checkout has the tag (a CI clone
// one commit deep does not). Proves legacy_verdict() is what 3.27 really did.
$old_source = (string) shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' show v3.27.2:tcgiant-sync.php 2>&1' );

// --- the children: one request of a site each ---------------------------------

$PLUGIN_CHILD = <<<'PHP'
<?php
// Loads a plugin main file the way WordPress does, and reports its verdict.
define( 'ABSPATH', __DIR__ . '/' );
$S = json_decode( (string) file_get_contents( $argv[1] ), true );
if ( isset( $S['http_host'] ) ) { $_SERVER['HTTP_HOST'] = $S['http_host']; } else { unset( $_SERVER['HTTP_HOST'] ); }
if ( isset( $S['wp_home'] ) ) { define( 'WP_HOME', $S['wp_home'] ); }
if ( array_key_exists( 'defined', $S ) ) { define( 'TCGIANT_SYNC_IS_STAGING', (bool) $S['defined'] ); }
function wp_get_environment_type() { return $GLOBALS['S']['env'] ?? 'production'; }
function wp_load_alloptions() { return array( 'home' => $GLOBALS['S']['home'], 'siteurl' => $GLOBALS['S']['home'] ); }
function get_option( $n, $d = false ) { return ( 'home' === $n || 'siteurl' === $n ) ? $GLOBALS['S']['home'] : ( $GLOBALS['S']['options'][ $n ] ?? $d ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://plugins.invalid/'; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function add_action() {}
function register_deactivation_hook() {}
function __( $t ) { return $t; }
require $S['plugin'];
echo "\n@@RESULT@@" . json_encode( array(
	'staging' => TCGIANT_SYNC_IS_STAGING,
	'reason'  => class_exists( 'TCGiant_Sync_Environment', false ) ? TCGiant_Sync_Environment::reason() : null,
) );
PHP;

$WORLD_CHILD = <<<'PHP'
<?php
// One request of a WordPress shop, as far as the staging check and the code it
// guards use one. The scenario file says where the site lives and what to do.
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

$S = json_decode( (string) file_get_contents( $argv[1] ), true );

if ( isset( $S['http_host'] ) ) { $_SERVER['HTTP_HOST'] = $S['http_host']; } else { unset( $_SERVER['HTTP_HOST'] ); }
if ( array_key_exists( 'defined', $S ) ) { define( 'TCGIANT_SYNC_IS_STAGING', (bool) $S['defined'] ); }

$OPTIONS    = $S['options'] ?? array();
$META       = $S['meta'] ?? array();
$ENDED_ROWS = $S['ended_rows'] ?? array();
$WIRE       = array(); // what reached the HTTP layer, i.e. eBay
$LOG        = array();
$QUERIES    = array();
$INCREMENTS = 0;       // the daily Trading API call counter
$ORDERS     = array();

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $t ) { return $t; }
function _n( $single, $plural, $n ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return esc_html( $t ); }
function esc_xml( $t ) { return esc_html( $t ); }
function esc_url( $t ) { return (string) $t; }
function esc_html__( $t ) { return esc_html( $t ); }
function esc_html_e( $t ) { echo esc_html( $t ); }
function admin_url( $p = '' ) { return 'https://site.invalid/wp-admin/' . $p; }
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . $a . '">'; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=nonce-' . $a; }
function add_action() {}
function add_filter() {}
function wp_get_environment_type() { return $GLOBALS['S']['env'] ?? 'production'; }
function wp_load_alloptions() { return array( 'home' => $GLOBALS['S']['home'], 'siteurl' => $GLOBALS['S']['home'] ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['OPTIONS'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = 'yes' ) { if ( ! array_key_exists( $n, $GLOBALS['OPTIONS'] ) ) { $GLOBALS['OPTIONS'][ $n ] = $v; } return true; }
function delete_option( $n ) { unset( $GLOBALS['OPTIONS'][ $n ] ); return true; }
function get_transient( $n ) {
	return $GLOBALS['OPTIONS'][ '_transient_' . $n ] ?? false;
}
function set_transient( $n, $v, $e = 0 ) { $GLOBALS['OPTIONS'][ '_transient_' . $n ] = $v; return true; }
function delete_transient( $n ) { unset( $GLOBALS['OPTIONS'][ '_transient_' . $n ] ); return true; }
function wp_cache_delete() { return true; }
function wp_rand( $a = 0, $b = 0 ) { return $a; }
function wp_json_encode( $v, $f = 0, $d = 512 ) { return json_encode( $v, $f, $d ); }
function wp_generate_uuid4() { return sprintf( '%08x-%04x-4%03x-a%03x-%012x', mt_rand(), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand() ); }
function wp_generate_password( $l = 12, $s = true ) { return str_repeat( 'x', $l ); }
function current_time( $t, $g = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_post_meta( $id, $k = '', $s = false ) { return $GLOBALS['META'][ (int) $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['META'][ (int) $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['META'][ (int) $id ][ $k ] ); return true; }
function check_ajax_referer() { return 1; }
function check_admin_referer() { return 1; }
function current_user_can() { return $GLOBALS['S']['can'] ?? true; }
function sanitize_text_field( $t ) { return trim( (string) $t ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
class Harness_Sent extends Exception {
	public $payload;
	public function __construct( $p ) { parent::__construct( 'sent' ); $this->payload = $p; }
}
// Each of these ends a real request; here it ends the call, carrying what it sent.
function wp_send_json_success( $d = null ) { throw new Harness_Sent( array( 'success' => true, 'data' => $d ) ); }
function wp_send_json_error( $d = null ) { throw new Harness_Sent( array( 'success' => false, 'data' => $d ) ); }
function wp_safe_redirect( $to ) { throw new Harness_Sent( array( 'redirect' => $to ) ); }
function wp_die( $m = '' ) { throw new Harness_Sent( array( 'die' => $m ) ); }
function wp_get_referer() { return 'https://site.invalid/wp-admin/admin.php?page=tcgiant-sync'; }
function wp_get_current_user() { $u = new stdClass(); $u->ID = 7; $u->user_login = 'coinshop-admin'; return $u; }
function get_current_user_id() { return 7; }
function get_current_screen() { $s = new stdClass(); $s->id = $GLOBALS['S']['screen'] ?? 'toplevel_page_tcgiant-sync'; return $s; }
function wc_get_order( $id ) { return $GLOBALS['ORDERS'][ (int) $id ] ?? false; }

class Harness_Wpdb {
	public $options = 'wp_options'; public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	public function query( $q ) {
		$GLOBALS['QUERIES'][] = $q;
		if ( false !== strpos( $q, 'option_value + 1' ) ) { $GLOBALS['INCREMENTS']++; return 1; }
		return 0;
	}
	public function get_var( $q ) {
		$GLOBALS['QUERIES'][] = $q;
		// The day's call count, read from the row it is counted in (3.28.0).
		if ( false !== strpos( $q, "'tcgiant_sync_api_calls_" ) ) { return (string) $GLOBALS['INCREMENTS']; }
		foreach ( $GLOBALS['S']['skus'] ?? array() as $sku => $id ) {
			if ( false !== strpos( $q, "'" . $sku . "'" ) ) { return (string) $id; }
		}
		return null;
	}
	// The same lookup, as a list: the stock push asks for up to two (3.28.0).
	public function get_col( $q ) {
		$id = $this->get_var( $q );
		return null === $id ? array() : array( $id );
	}
	public function get_results( $q, $o = null ) { $GLOBALS['QUERIES'][] = $q; return $GLOBALS['ENDED_ROWS']; }
}
$wpdb = new Harness_Wpdb();

class TCGiant_Sync_Logger {
	public static function log( $m, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $m ); }
	public static function error( $m ) { self::log( $m, 'error' ); }
	public static function warning( $m ) { self::log( $m, 'warning' ); }
}
class TCGiant_Sync_OAuth {
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() { return array( 'marketplace' => 'EBAY_US' ); }
	public function get_access_token() { $GLOBALS['TOKEN_ASKED'] = true; return 'harness-token'; }
	public function refresh_access_token() { return false; }
}
class TCGiant_Sync_DB {
	public static function table_name() { return 'wp_tcgiant_listings'; }
	public static function table_exists() { return true; }
	public static function upsert( $d ) { return true; }
}

// eBay. Every request is recorded - a Trading call by its name, a REST call by
// method and path - and answered as eBay would.
function wp_remote_request( $url, $args ) {
	$call   = $args['headers']['X-EBAY-API-CALL-NAME'] ?? '';
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$path   = preg_replace( '#^https://api\.ebay\.com/#', '', (string) strtok( $url, '?' ) );
	$GLOBALS['WIRE'][] = '' !== $call ? $call : $method . ' ' . $path;
	if ( '' !== $call ) {
		$inner = 'UploadSiteHostedPictures' === $call ? '<SiteHostedPictureDetails><FullURL>https://i.ebayimg.com/00/s/x.jpg</FullURL></SiteHostedPictureDetails>' : '<ItemID>226100009999</ItemID>';
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => '<?xml version="1.0" encoding="UTF-8"?><' . $call . 'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack>' . $inner . '</' . $call . 'Response>' );
	}
	if ( 'GET' === $method && false !== strpos( $path, 'inventory_item/' ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'sku' => 'SKU-1', 'availability' => array( 'shipToLocationAvailability' => array( 'quantity' => 5 ) ) ) ) );
	}
	return array( 'headers' => array(), 'response' => array( 'code' => 'GET' === $method ? 200 : 204 ), 'body' => 'GET' === $method ? '{}' : '' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require '__ROOT__/includes/class-tcgiant-sync-environment.php';
require '__ROOT__/includes/class-tcgiant-sync-api.php';
// Loaded with the plugin, as tcgiant-sync.php does: a stock push is watched
// by it since 3.28.0, so one the host stops is tried again.
require '__ROOT__/includes/class-tcgiant-sync-runtime.php';
// The exporter's own constants come from the catalogue: an exporter made here
// for the bulk push needs it.
require '__ROOT__/includes/class-tcgiant-sync-catalog.php';
require '__ROOT__/includes/class-tcgiant-sync-exporter.php';
require '__ROOT__/includes/class-tcgiant-sync-inventory.php';
require '__ROOT__/includes/class-tcgiant-sync-cron.php';
require '__ROOT__/includes/class-tcgiant-sync-jobs.php';
require '__ROOT__/includes/class-tcgiant-sync-orders.php';
require '__ROOT__/admin/class-tcgiant-sync-admin.php';

// As tcgiant-sync.php does when the plugin loads.
TCGiant_Sync_Environment::boot();

/** An eBay that refuses the stock calls with a given error, sending nothing. */
class Refusing_eBay extends TCGiant_Sync_API {
	public $error;
	public function end_item( $item_id ) { $GLOBALS['WIRE'][] = 'EndItem (answered by the harness)'; return $this->error; }
	public function update_inventory_item_availability( $sku, $quantity, $item_id = '' ) { $GLOBALS['WIRE'][] = 'quantity update (answered by the harness)'; return $this->error; }
	// The quantity call the push makes since 3.28.0.
	public function revise_inventory_status( $item_id, $quantity, $sku = '' ) { $GLOBALS['WIRE'][] = 'quantity update (answered by the harness)'; return $this->error; }
	// And what eBay says about the listing when asked: still live, 1 left.
	public function get_item( $item_id ) {
		$GLOBALS['WIRE'][] = 'GetItem (answered by the harness)';
		return array( 'Ack' => 'Success', 'Item' => array( 'ItemID' => $item_id, 'Quantity' => '1', 'SellingStatus' => array( 'ListingStatus' => 'Active', 'QuantitySold' => '0' ) ) );
	}
}

function use_api( $api ) {
	$slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
	if ( PHP_VERSION_ID < 80100 ) { $slot->setAccessible( true ); }
	$slot->setValue( null, $api );
	return $api;
}
function bare( $class ) { return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor(); }
function warnings() {
	return count( array_filter( $GLOBALS['LOG'], function ( $l ) { return 'warning' === $l[0] && false !== strpos( $l[1], 'was not sent' ); } ) );
}
function errors() {
	return count( array_filter( $GLOBALS['LOG'], function ( $l ) { return 'error' === $l[0]; } ) );
}
function outcome( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'sent'; }
function render( $callable ) { ob_start(); call_user_func( $callable ); return (string) ob_get_clean(); }

$api = use_api( new TCGiant_Sync_API() );
$R   = array( 'staging' => TCGIANT_SYNC_IS_STAGING, 'reason' => TCGiant_Sync_Environment::reason() );

switch ( $S['act'] ) {

	// Everything the plugin has that changes something on eBay.
	case 'writes':
		$picture = __DIR__ . '/picture.jpg';
		file_put_contents( $picture, 'not really a jpeg' );
		$calls = array(
			'Inventory API quantity update (PUT)' => function () use ( $api ) { return $api->update_inventory_item_availability( 'SKU-1', 3 ); },
			'ReviseInventoryStatus'               => function () use ( $api ) { return $api->update_trading_api_stock( '110000000501', 3 ); },
			'EndItem'                             => function () use ( $api ) { return $api->end_item( '110000000501' ); },
			'EndItem, from a stock of 0'          => function () use ( $api ) { return $api->update_trading_api_stock( '110000000501', 0 ); },
			'RelistItem'                          => function () use ( $api ) { return $api->relist_item( '110000000501', 1 ); },
			'RelistFixedPriceItem'                => function () use ( $api ) { return $api->relist_fixed_price_item( '110000000501', 2 ); },
			'AddItem'                             => function () use ( $api ) { return $api->add_item( "<Item>\n<Title>1oz Britannia</Title>\n</Item>" ); },
			'AddFixedPriceItem'                   => function () use ( $api ) { return $api->add_fixed_price_item( "<Item>\n<Title>1oz Britannia</Title>\n</Item>" ); },
			'ReviseItem'                          => function () use ( $api ) { return $api->revise_item( '<Item><ItemID>110000000501</ItemID></Item>' ); },
			'ReviseFixedPriceItem'                => function () use ( $api ) { return $api->revise_fixed_price_item( '<Item><ItemID>110000000501</ItemID></Item>' ); },
			'CompleteSale'                        => function () use ( $api ) { return $api->trading_api_request( 'CompleteSale', '<OrderID>12-34567-89012</OrderID>' ); },
			'SetNotificationPreferences'          => function () use ( $api ) { return $api->trading_api_request( 'SetNotificationPreferences', '' ); },
			'ReviseMyMessages, on no list at all' => function () use ( $api ) { return $api->trading_api_request( 'ReviseMyMessages', '' ); },
			'UploadSiteHostedPictures'            => function () use ( $api, $picture ) { return $api->upload_picture( $picture ); },
			'POST, publishing an offer'           => function () use ( $api ) { return $api->request( 'sell/inventory/v1/offer/1/publish', 'POST', array( 'x' => 1 ) ); },
			'DELETE, an inventory item'           => function () use ( $api ) { return $api->request( 'sell/inventory/v1/inventory_item/SKU-1', 'DELETE' ); },
			'put, in lower case'                  => function () use ( $api ) { return $api->request( 'sell/inventory/v1/inventory_item/SKU-1', 'put', array( 'a' => 1 ) ); },
		);
		$R['count_before'] = $api->get_daily_call_count();
		foreach ( $calls as $name => $fn ) {
			$GLOBALS['WIRE']        = array();
			$GLOBALS['TOKEN_ASKED'] = false;
			$r = $fn();
			$R['calls'][ $name ] = array( 'outcome' => outcome( $r ), 'wire' => $GLOBALS['WIRE'], 'token' => $GLOBALS['TOKEN_ASKED'], 'message' => is_wp_error( $r ) ? $r->get_error_message() : '' );
		}
		$R['count_after'] = $api->get_daily_call_count();
		$R['warnings']    = warnings();
		$R['errors']      = errors();
		$R['blocked']     = TCGiant_Sync_Environment::blocked_count();
		break;

	// Everything that only reads, which a copy still needs.
	case 'reads':
		$calls = array(
			'GetItem'               => function () use ( $api ) { return $api->get_item( '110000000501' ); },
			'GetSellerEvents'       => function () use ( $api ) { return $api->get_seller_events( '2026-10-01T00:00:00.000Z', '2026-10-01T01:00:00.000Z' ); },
			'GetOrders'             => function () use ( $api ) { return $api->trading_api_request( 'GetOrders', '<NumberOfDays>1</NumberOfDays>' ); },
			'GetUser'               => function () use ( $api ) { return $api->get_seller_user_id(); },
			'VerifyAddItem'         => function () use ( $api ) { return $api->verify_add_item( '<Item><Title>1oz Britannia</Title></Item>' ); },
			'GET inventory item'    => function () use ( $api ) { return $api->get_inventory_item( 'SKU-1' ); },
			'GET offers'            => function () use ( $api ) { return $api->get_offers( 'SKU-1' ); },
			'GET shipping policies' => function () use ( $api ) { return $api->get_fulfillment_policies( 'EBAY_US' ); },
		);
		foreach ( $calls as $name => $fn ) {
			$GLOBALS['WIRE'] = array();
			$r = $fn();
			$R['calls'][ $name ] = array( 'outcome' => outcome( $r ), 'wire' => $GLOBALS['WIRE'] );
		}
		$R['warnings'] = warnings();
		break;

	// The last door, reached directly, as a method added later might.
	case 'backstop':
		$door = new ReflectionMethod( 'TCGiant_Sync_API', 'request_with_retry' );
		if ( PHP_VERSION_ID < 80100 ) { $door->setAccessible( true ); }
		$cases = array(
			'PUT'                        => array( 'method' => 'PUT', 'headers' => array() ),
			'POST naming no Trading call' => array( 'method' => 'POST', 'headers' => array() ),
			'Trading EndItem'            => array( 'method' => 'POST', 'headers' => array( 'X-EBAY-API-CALL-NAME' => 'EndItem' ) ),
			'Trading GetItem'            => array( 'method' => 'POST', 'headers' => array( 'X-EBAY-API-CALL-NAME' => 'GetItem' ) ),
			'GET'                        => array( 'method' => 'GET', 'headers' => array() ),
		);
		foreach ( $cases as $name => $args ) {
			$GLOBALS['WIRE'] = array();
			$r = $door->invoke( $api, 'https://api.ebay.com/ws/api.dll', $args );
			$R['calls'][ $name ] = array( 'outcome' => outcome( $r ), 'wire' => count( $GLOBALS['WIRE'] ) );
		}
		break;

	case 'ended':
		$block = TCGiant_Sync_Environment::write_block();
		$R['message']      = $block ? $block->get_error_message() : null;
		$R['data']         = $block ? $block->get_error_data() : null;
		$R['is_ended']     = TCGiant_Sync_Exporter::is_ended_listing_error( $block );
		$R['by_code']      = TCGiant_Sync_Exporter::is_ended_listing_error( new WP_Error( 'staging_blocked', 'Translated: the listing has ended.' ) );
		$R['really_ended'] = TCGiant_Sync_Exporter::is_ended_listing_error( new WP_Error( 'api_error', 'You are not allowed to revise ended listings.' ) );
		break;

	// The stock push, as the WooCommerce sale queues it.
	case 'stock':
		foreach ( array( 0, 3 ) as $qty ) {
			$GLOBALS['META']    = $S['meta'];
			$GLOBALS['WIRE']    = array();
			$GLOBALS['QUERIES'] = array();
			bare( 'TCGiant_Sync_Inventory' )->process_ebay_stock_update( 'SKU-1', $qty );
			$R['runs'][ $qty ] = array( 'wire' => $GLOBALS['WIRE'], 'meta' => $GLOBALS['META'][501], 'queries' => count( $GLOBALS['QUERIES'] ) );
		}
		// And a push queued since 3.28.0, which names the product: a website
		// sale, which would otherwise read eBay first.
		$GLOBALS['META']    = $S['meta'];
		$GLOBALS['WIRE']    = array();
		$GLOBALS['QUERIES'] = array();
		bare( 'TCGiant_Sync_Inventory' )->process_stock_push( array( 'product_id' => 501, 'reason' => 'order', 'qty' => 1 ) );
		$R['direct'] = array( 'wire' => $GLOBALS['WIRE'], 'meta' => $GLOBALS['META'][501], 'queries' => count( $GLOBALS['QUERIES'] ) );
		$R['warnings'] = warnings();
		$R['errors']   = errors();
		break;

	// The same push on a live site whose eBay call came back with an error.
	//
	// Since 3.28.0 the push reads the product's own stock and listing, so the
	// product is played here as far as that goes; and what an error from eBay
	// meant is read back from eBay, which the harness answers too.
	case 'stock_answered':
		require_once '__ROOT__/includes/class-tcgiant-sync-listing-link.php';
		class Harness_Stock_Product {
			private $id;
			public function __construct( $id ) { $this->id = (int) $id; }
			public function get_id() { return $this->id; }
			public function get_parent_id() { return 0; }
			public function is_type( $t ) { return 'simple' === $t; }
			public function get_meta( $k ) { return $GLOBALS['META'][ $this->id ][ $k ] ?? ''; }
			public function get_sku( $c = 'view' ) { return (string) ( $GLOBALS['META'][ $this->id ]['_sku'] ?? '' ); }
			public function managing_stock() { return 'yes' === ( $GLOBALS['META'][ $this->id ]['_manage_stock'] ?? '' ); }
			public function get_stock_quantity() { return $this->managing_stock() ? (int) $GLOBALS['META'][ $this->id ]['_stock'] : null; }
		}
		function wc_get_product( $id ) { return isset( $GLOBALS['META'][ (int) $id ] ) ? new Harness_Stock_Product( $id ) : false; }
		function get_post_status( $id ) { return isset( $GLOBALS['META'][ (int) $id ] ) ? 'publish' : false; }
		function as_schedule_single_action( $when, $hook, $args = array(), $group = '' ) { $GLOBALS['QUEUED'][] = $hook; return 1; }
		function as_unschedule_action( $hook, $args = array(), $group = '' ) { $i = array_search( $hook, array_reverse( $GLOBALS['QUEUED'], true ), true ); if ( false !== $i ) { unset( $GLOBALS['QUEUED'][ $i ] ); $GLOBALS['QUEUED'] = array_values( $GLOBALS['QUEUED'] ); } return $i; }
		$ebay = use_api( new Refusing_eBay() );
		$keys = array_flip( array( '_ebay_item_id', '_ebay_sku', '_ebay_listing_status', '_ebay_stock_settled_at', '_ebay_stock_settled_item', '_ebay_end_reason' ) );
		foreach ( $S['answers'] as $label => $answer ) {
			foreach ( array( 0, 3 ) as $qty ) {
				$GLOBALS['META']   = $S['meta'];
				$GLOBALS['META'][501]['_manage_stock'] = 'yes';
				$GLOBALS['META'][501]['_stock']        = $qty;
				$GLOBALS['WIRE']   = array();
				$GLOBALS['QUEUED'] = array();
				$ebay->error       = new WP_Error( $answer[0], $answer[1], $answer[2] ?? '' );
				bare( 'TCGiant_Sync_Inventory' )->process_ebay_stock_update( 'SKU-1', $qty );
				$R['runs'][ $label ][ $qty ] = array( 'wire' => $GLOBALS['WIRE'], 'meta' => array_intersect_key( $GLOBALS['META'][501], $keys ), 'queued' => $GLOBALS['QUEUED'] );
			}
		}
		break;

	case 'auto_relist':
		bare( 'TCGiant_Sync_Cron' )->auto_relist_ended_items();
		$R['wire']     = $GLOBALS['WIRE'];
		$R['queries']  = count( $GLOBALS['QUERIES'] );
		$R['meta']     = $GLOBALS['META'];
		$R['warnings'] = warnings();
		break;

	case 'jobs':
		foreach ( array( 'bulk_end', 'bulk_push', 'bulk_relist', 'bulk_verify', 'bulk_keep_stock' ) as $type ) {
			$_POST = array( 'type' => $type, 'product_ids' => array( 501 ) );
			try {
				bare( 'TCGiant_Sync_Jobs' )->ajax_start_job();
				$R['jobs'][ $type ] = null;
			} catch ( Harness_Sent $sent ) {
				$R['jobs'][ $type ] = array( 'success' => $sent->payload['success'], 'message' => $sent->payload['data']['message'] ?? null, 'job' => isset( $sent->payload['data']['job_id'] ) );
			}
		}
		$R['wire'] = $GLOBALS['WIRE'];
		break;

	// The products list's Push to eBay bulk action, through the exporter's own
	// entry, where 3.27.4 begins its batch: refused before any of that.
	case 'bulk_push_products':
		$R['queued']   = bare( 'TCGiant_Sync_Exporter' )->bulk_push_products( array( 501, 502 ) );
		$R['state']    = array_key_exists( 'tcgiant_export_state', $GLOBALS['OPTIONS'] );
		$R['meta']     = $GLOBALS['META'];
		$R['wire']     = $GLOBALS['WIRE'];
		$R['warnings'] = warnings();
		break;

	case 'tracking':
		$order = new class() {
			public $notes = array();
			public function get_meta( $k ) { return array( '_ebay_order_id' => '12-34567-89012', '_tracking_number' => 'RM123456789GB', '_tracking_provider' => 'Royal Mail' )[ $k ] ?? ''; }
			public function get_items() { return array( new class() { public function get_meta( $k ) { return '_ebay_item_id' === $k ? '110000000501' : ''; } } ); }
			public function add_order_note( $n ) { $this->notes[] = $n; }
		};
		$GLOBALS['ORDERS'][77] = $order;
		bare( 'TCGiant_Sync_Orders' )->push_tracking_to_ebay( 77 );
		bare( 'TCGiant_Sync_Orders' )->push_tracking_to_ebay( 77 );
		$R['wire']     = $GLOBALS['WIRE'];
		$R['notes']    = $order->notes;
		$R['warnings'] = warnings();
		$R['errors']   = errors();
		break;

	// "This is my live shop", pressed.
	case 'confirm':
		try {
			bare( 'TCGiant_Sync_Admin' )->handle_confirm_live();
			$R['sent'] = null;
		} catch ( Harness_Sent $sent ) {
			$R['sent'] = $sent->payload;
		}
		$R['record']  = $GLOBALS['OPTIONS'][ TCGiant_Sync_Environment::CONFIRMED_OPTION ] ?? null;
		$R['options'] = $GLOBALS['OPTIONS'];
		$R['log']     = $GLOBALS['LOG'];
		$R['after']   = TCGiant_Sync_Environment::copy_reason();
		break;

	case 'notices':
		$admin = bare( 'TCGiant_Sync_Admin' );
		$R['staging'] = render( array( $admin, 'staging_admin_notice' ) );
		$R['changed'] = render( array( $admin, 'environment_change_notice' ) );
		break;

	// An admin request on a site that has just updated from 3.27.
	case 'verdict':
		TCGiant_Sync_Environment::record_verdict();
		TCGiant_Sync_Environment::record_verdict();
		$admin = bare( 'TCGiant_Sync_Admin' );
		$R['stored']  = $GLOBALS['OPTIONS'][ TCGiant_Sync_Environment::VERDICT_OPTION ] ?? null;
		$R['options'] = $GLOBALS['OPTIONS'];
		$R['log']     = $GLOBALS['LOG'];
		$R['staging'] = render( array( $admin, 'staging_admin_notice' ) );
		$R['changed'] = render( array( $admin, 'environment_change_notice' ) );
		break;

	case 'dismiss':
		try {
			bare( 'TCGiant_Sync_Admin' )->handle_dismiss_env_notice();
		} catch ( Harness_Sent $sent ) {
			$R['sent'] = $sent->payload;
		}
		$R['stored'] = $GLOBALS['OPTIONS'][ TCGiant_Sync_Environment::VERDICT_OPTION ] ?? null;
		break;
}

echo "\n@@RESULT@@" . json_encode( $R );
PHP;

file_put_contents( "$WORLD/plugin.php", $PLUGIN_CHILD );
file_put_contents( "$WORLD/world.php", str_replace( '__ROOT__', $ROOT, $WORLD_CHILD ) );

/** Run one request of a site in a PHP process of its own; its result, or null. */
function child( $script, array $scenario ) {
	static $n = 0;
	$file = $GLOBALS['WORLD'] . '/scenario-' . ( ++$n ) . '.json';
	file_put_contents( $file, json_encode( $scenario ) );
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $GLOBALS['WORLD'] . '/' . $script ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	$at  = strrpos( $out, '@@RESULT@@' );
	if ( false === $at ) {
		echo "      (the request died)\n" . preg_replace( '/^/m', '      ', $out ) . "\n";
		return null;
	}
	return json_decode( substr( $out, $at + 10 ), true );
}

/** The real tcgiant-sync.php, loaded as WordPress loads it. */
function plugin( array $scenario, $file = null ) {
	$scenario['plugin'] = null !== $file ? $file : $GLOBALS['ROOT'] . '/tcgiant-sync.php';
	return child( 'plugin.php', $scenario );
}

/** A site, one request of it, doing one thing. */
function site( $act, array $scenario = array() ) {
	$scenario['act'] = $act;
	$scenario += array( 'home' => 'https://numismax.com', 'env' => 'production' );
	return child( 'world.php', $scenario );
}

function ever_sent( $calls ) {
	$sent = array();
	foreach ( (array) $calls as $name => $c ) {
		if ( ! empty( $c['wire'] ) ) {
			$sent[] = $name;
		}
	}
	return $sent;
}

if ( 0 === strpos( ltrim( $old_source ), '<?php' ) ) {
	@mkdir( "$WORLD/old", 0777, true );
	file_put_contents( "$WORLD/old/tcgiant-sync.php", $old_source );
	$disagree  = array();
	$all_hosts = array_merge( array_keys( $COPIES ), $LIVES );
	foreach ( $all_hosts as $url ) {
		$real = plugin( array( 'home' => $url, 'http_host' => http_host_of( $url ) ), "$WORLD/old/tcgiant-sync.php" );
		if ( null === $real || $real['staging'] !== legacy( http_host_of( $url ) ) ) {
			$disagree[] = $url;
		}
	}
	check( 'the real 3.27.2 file agrees with that copy of its check on all ' . count( $all_hosts ) . ' addresses', $disagree, array() );
	$cli = plugin( array( 'home' => 'https://staging.numismax.com' ), "$WORLD/old/tcgiant-sync.php" );
	check( '  and from WP-CLI let staging.numismax.com send to eBay', $cli['staging'] ?? null, false );
} else {
	echo "  (the real 3.27.2 file was not compared: this checkout has no v3.27.2 tag)\n";
}

echo "\nWP-CLI AND SYSTEM CRON GET THE SAME ANSWER AS THE WEB - THE REAL tcgiant-sync.php\n" . str_repeat( '=', 132 ) . "\n";

$v = plugin( array( 'home' => 'https://staging.numismax.com' ) );
check( 'a copy run from WP-CLI, with no host name, is a copy', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:staging' ) );
$v = plugin( array( 'home' => 'https://staging.numismax.com', 'http_host' => 'staging.numismax.com' ) );
check( '  and so is the same copy in a web request', $v['staging'] ?? null, true );
$v = plugin( array( 'home' => 'https://staging.numismax.com', 'http_host' => 'example.com' ) );
check( '  and under the WP-CLI handbook\'s placeholder host name', $v['staging'] ?? null, true );
$v = plugin( array( 'home' => 'https://numismax.com', 'http_host' => 'staging.numismax.com' ) );
check( 'the stored address decides, never the request\'s host name', $v['staging'] ?? null, false );
$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'https://dev.numismax.com' ) );
check( 'WP_HOME in wp-config.php wins over the stored address', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:dev' ) );
$v = plugin( array( 'home' => 'https://numismax.com', 'env' => 'staging' ) );
check( 'WP_ENVIRONMENT_TYPE staging is a copy at any address', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'environment:staging' ) );
$v = plugin( array( 'home' => 'https://staging.numismax.com', 'defined' => false ) );
check( 'TCGIANT_SYNC_IS_STAGING false in wp-config.php still lets a site send', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );
$v = plugin( array( 'home' => 'https://numismax.com', 'defined' => true ) );
check( 'TCGIANT_SYNC_IS_STAGING true in wp-config.php still keeps one off eBay', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'wp-config' ) );
$v = plugin( array( 'home' => 'https://webdev-supplies.com', 'http_host' => 'webdev-supplies.com' ) );
check( 'webdev-supplies.com is a live shop now', $v['staging'] ?? null, false );

echo "\nA wp-config.php THAT BUILDS WP_HOME FROM THE REQUEST'S HOST NAME\n" . str_repeat( '=', 132 ) . "\n";

// Bitnami's, which AWS Lightsail ships: from WP-CLI, HTTP_HOST is set to
// 127.0.0.1 and WP_HOME is built from it, and the stored home is often only
// the install's placeholder. Judged on that, a live shop's WP-CLI runs read
// as a copy and their stock pushes were dropped. Such a run goes by what the
// last admin page load decided.
$bitnami = array( 'home' => 'http://127.0.0.1', 'wp_home' => 'http://127.0.0.1/', 'http_host' => '127.0.0.1' );
$v = plugin( $bitnami );
check( 'Bitnami, WP-CLI, nothing recorded yet: live, as before 3.28.0', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );
$v = plugin( $bitnami + array( 'options' => array( 'tcgiant_sync_env_verdict' => array( 'copy' => false, 'reason' => '' ) ) ) );
check( '  an admin page load judged it live: live', $v['staging'] ?? null, false );
$v = plugin( $bitnami + array( 'options' => array( 'tcgiant_sync_env_verdict' => array( 'copy' => true, 'reason' => 'address:label:staging' ) ) ) );
check( '  one judged it a copy: a copy, for the same reason', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:staging' ) );
$v = plugin( array( 'home' => 'https://staging.numismax.com', 'wp_home' => 'https://', 'options' => array( 'tcgiant_sync_env_verdict' => array( 'copy' => true, 'reason' => 'address:label:staging' ) ) ) );
check( 'a copy whose WP_HOME has no host from the command line: a copy', $v['staging'] ?? null, true );
$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'https://example.com', 'http_host' => 'example.com' ) );
check( 'the WP-CLI handbook\'s placeholder: judged by the last page load, live here', $v['staging'] ?? null, false );

// A staging copy whose wp-config.php has the handbook's snippet, and whose
// last admin page load judged it a copy. Read as live from system cron, it
// would send the queued stock changes and EndItems it carried over from the
// live shop to the live eBay account.
$copy_verdict = array( 'tcgiant_sync_env_verdict' => array( 'copy' => true, 'reason' => 'address:label:staging' ) );
$live_verdict = array( 'tcgiant_sync_env_verdict' => array( 'copy' => false, 'reason' => '' ) );
$v = plugin( array( 'home' => 'https://staging.numismax.com', 'wp_home' => 'https://example.com', 'http_host' => 'example.com', 'options' => $copy_verdict ) );
check( '  and a copy under it goes by its own last page load: a copy', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:staging' ) );

// Other names a wp-config.php makes up for the command line, with no port:
// localhost, and a bare machine name such as a Docker service's. Judged by
// that name, a live shop's WP-CLI and cron runs read as a copy and its
// stock pushes were dropped - oversells. Each goes by the last page load.
foreach ( array( 'localhost', 'web' ) as $made_up ) {
	$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'http://' . $made_up, 'http_host' => $made_up, 'options' => $live_verdict ) );
	check( 'a live shop whose wp-config.php names the command line ' . $made_up . ': live', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );
	$v = plugin( array( 'home' => 'https://staging.numismax.com', 'wp_home' => 'http://' . $made_up, 'http_host' => $made_up, 'options' => $copy_verdict ) );
	check( '  a copy under ' . $made_up . ' goes by its own last page load: a copy', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:staging' ) );
}

$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'http://localhost:8080' ) );
check( 'a WP_HOME written out in full still decides from the command line', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:localhost' ) );

// WP-CLI's --url, a wp-cli.yml url: or an @alias sets HTTP_HOST to the site's
// own name before wp-config.php runs. That run names the site, so its address
// decides - not a verdict copied from the live shop with its database. A
// host cron's "wp cron event run --due-now --url=..." on a fresh copy of live
// was judged live until someone opened its wp-admin, and sent the live shop's
// queued stock changes, from the copy, to the live eBay account.
$lv = array( 'tcgiant_sync_env_verdict' => array( 'copy' => false, 'reason' => '' ) );
$v  = plugin( array( 'home' => 'https://staging.numismax.com', 'wp_home' => 'https://staging.numismax.com', 'http_host' => 'staging.numismax.com', 'options' => $lv ) );
check( 'wp --url on a copy cloned from live: a copy, by its address', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:staging' ) );
$v = plugin( array( 'home' => 'https://env-numismax-staging.kinsta.cloud', 'wp_home' => 'https://env-numismax-staging.kinsta.cloud', 'http_host' => 'env-numismax-staging.kinsta.cloud', 'options' => $lv ) );
check( '  a Kinsta env- copy the same', $v['staging'] ?? null, true );
$v = plugin( array( 'home' => 'https://numismax.ddev.site', 'wp_home' => 'https://numismax.ddev.site', 'http_host' => 'numismax.ddev.site', 'options' => $lv ) );
check( '  DDEV, whose wp-cli.yml names the site', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:local-domain' ) );
$v = plugin( array( 'home' => 'http://localhost:8080', 'wp_home' => 'http://localhost:8080', 'http_host' => 'localhost:8080', 'options' => $lv ) );
check( '  localhost with a port - no wp-config.php makes one of those up', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:localhost' ) );
$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'https://numismax.com', 'http_host' => 'numismax.com', 'options' => array( 'tcgiant_sync_env_verdict' => array( 'copy' => true, 'reason' => 'address:label:staging' ) ) ) );
check( 'the live shop, with a verdict pushed in from staging: live, by its address', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );
$v = plugin( array( 'home' => 'http://127.0.0.1', 'wp_home' => 'http://127.0.0.1/', 'http_host' => '127.0.0.1', 'options' => array( 'tcgiant_sync_env_verdict' => array( 'copy' => true, 'reason' => 'address:label:staging' ) ) ) );
check( 'Bitnami, with no --url, still goes by the last page load', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:label:staging' ) );

echo "\nON A COPY, NOTHING THAT CHANGES EBAY REACHES IT\n" . str_repeat( '=', 132 ) . "\n";

$copy = site( 'writes', array( 'home' => 'https://staging.numismax.com', 'skus' => array( 'SKU-1' => 501 ) ) );
check( 'the site is a copy', $copy['reason'] ?? null, 'address:label:staging' );
foreach ( (array) ( $copy['calls'] ?? array() ) as $name => $c ) {
	if ( 'Inventory API quantity update (PUT)' === $name ) {
		continue;
	}
	check( $name . ' is refused, and nothing is sent', array( $c['outcome'], $c['wire'] ), array( 'staging_blocked', array() ) );
}
$put = $copy['calls']['Inventory API quantity update (PUT)'] ?? array();
check( 'the Inventory API quantity update is refused', $put['outcome'] ?? null, 'staging_blocked' );
check( '  after reading the item, which is all that reaches eBay', $put['wire'] ?? null, array( 'GET sell/inventory/v1/inventory_item/SKU-1' ) );
check( 'a refused REST write does not set off a token refresh', $copy['calls']['POST, publishing an offer']['token'] ?? null, false );
check( 'nor does a refused picture upload', $copy['calls']['UploadSiteHostedPictures']['token'] ?? null, false );
check( 'a refusal is not counted against the day\'s eBay budget', array( $copy['count_before'] ?? null, $copy['count_after'] ?? null ), array( 0, 0 ) );
check( 'seventeen refusals make one warning in the log, not seventeen', $copy['warnings'] ?? null, 1 );
check( '  and no errors', $copy['errors'] ?? null, 0 );
check( '  each is counted for the notice', $copy['blocked'] ?? null, 17 );
check( 'the refusal says why', $copy['calls']['EndItem']['message'] ?? null, 'Not sent to eBay: this site looks like a staging or development copy (its address has a "staging" subdomain).' );

echo "\nON A COPY, READING FROM EBAY CARRIES ON\n" . str_repeat( '=', 132 ) . "\n";

// The delta, the per-sale checks and the relist guard all read. 3.27.0's
// "sold coins come off" depends on every one of these.
$reads = site( 'reads', array( 'home' => 'https://staging.numismax.com' ) );
$want  = array(
	'GetItem'               => array( 'GetItem' ),
	'GetSellerEvents'       => array( 'GetSellerEvents' ),
	'GetOrders'             => array( 'GetOrders' ),
	'GetUser'               => array( 'GetUser' ),
	'VerifyAddItem'         => array( 'VerifyAddItem' ),
	'GET inventory item'    => array( 'GET sell/inventory/v1/inventory_item/SKU-1' ),
	'GET offers'            => array( 'GET sell/inventory/v1/offer' ),
	'GET shipping policies' => array( 'GET sell/account/v1/fulfillment_policy' ),
);
foreach ( $want as $name => $wire ) {
	check( $name . ' reaches eBay', $reads['calls'][ $name ]['wire'] ?? null, $wire );
}
check( 'and nothing about reading is logged as refused', $reads['warnings'] ?? null, 0 );

echo "\nTHE LAST DOOR TO EBAY CANNOT BE WALKED ROUND\n" . str_repeat( '=', 132 ) . "\n";

$door = site( 'backstop', array( 'home' => 'https://staging.numismax.com' ) );
check( 'a PUT sent straight to the HTTP layer is refused', $door['calls']['PUT'] ?? null, array( 'outcome' => 'staging_blocked', 'wire' => 0 ) );
check( 'a POST naming no Trading call is refused', $door['calls']['POST naming no Trading call'] ?? null, array( 'outcome' => 'staging_blocked', 'wire' => 0 ) );
check( 'a Trading EndItem is refused by the call it names', $door['calls']['Trading EndItem'] ?? null, array( 'outcome' => 'staging_blocked', 'wire' => 0 ) );
check( 'a Trading GetItem, also a POST, goes through', $door['calls']['Trading GetItem'] ?? null, array( 'outcome' => 'sent', 'wire' => 1 ) );
check( 'a GET goes through', $door['calls']['GET'] ?? null, array( 'outcome' => 'sent', 'wire' => 1 ) );

echo "\nTHE LIVE SHOP SENDS - THE GUARD IS NOT A BLANKET BLOCK\n" . str_repeat( '=', 132 ) . "\n";

$live = site( 'writes', array( 'home' => 'https://numismax.com', 'skus' => array( 'SKU-1' => 501 ) ) );
check( 'the site is live', array( $live['staging'] ?? null, $live['reason'] ?? null ), array( false, '' ) );
check( 'every one of those changes reaches eBay', ever_sent( $live['calls'] ?? array() ), array_keys( (array) ( $copy['calls'] ?? array() ) ) );
check( '  the quantity update as a read and then the PUT', $live['calls']['Inventory API quantity update (PUT)']['wire'] ?? null, array( 'GET sell/inventory/v1/inventory_item/SKU-1', 'PUT sell/inventory/v1/inventory_item/SKU-1' ) );
check( '  and every Trading call is counted, as before', $live['count_after'] ?? null, 13 );
check( '  with nothing logged as refused', array( $live['warnings'] ?? null, $live['blocked'] ?? null ), array( 0, 0 ) );
$door = site( 'backstop' );
check( 'the last door lets the live shop\'s PUT through', $door['calls']['PUT'] ?? null, array( 'outcome' => 'sent', 'wire' => 1 ) );

echo "\nA REFUSAL IS NOT AN ENDED LISTING\n" . str_repeat( '=', 132 ) . "\n";

$ended = site( 'ended', array( 'home' => 'https://dev.numismax.com' ) );
check( 'the exporter does not read a refusal as an ended listing', $ended['is_ended'] ?? null, false );
check( '  nor one translated into words that would match', $ended['by_code'] ?? null, false );
check( '  while eBay\'s own "revise ended listings" still matches', $ended['really_ended'] ?? null, true );
$tainted = array();
foreach ( $forbidden as $f ) {
	if ( false !== stripos( (string) ( $ended['message'] ?? 'ended' ), $f ) ) {
		$tainted[] = $f;
	}
}
check( 'the refusal contains none of the words the stock push looks for', $tainted, array() );
check( 'it carries its reason', $ended['data'] ?? null, array( 'reason' => 'staging', 'detail' => 'address:label:dev' ) );

echo "\nON A COPY, A STOCK PUSH LEAVES NO TRACE\n" . str_repeat( '=', 132 ) . "\n";

$coin  = array( 501 => array( '_ebay_item_id' => '110000000501', '_ebay_sku' => 'SKU-1', '_ebay_listing_status' => 'Active' ) );
$stock = site( 'stock', array( 'home' => 'https://staging.numismax.com', 'skus' => array( 'SKU-1' => 501 ), 'meta' => $coin ) );
check( 'a coin selling out on a copy sends nothing', $stock['runs'][0]['wire'] ?? null, array() );
check( '  its listing is still Active, and no settle is recorded', $stock['runs'][0]['meta'] ?? null, $coin[501] );
check( '  not even a database lookup is spent on it', $stock['runs'][0]['queries'] ?? null, 0 );
check( 'a quantity of 3 sends nothing either - not even the Inventory API read', $stock['runs'][3]['wire'] ?? null, array() );
check( '  and changes nothing', $stock['runs'][3]['meta'] ?? null, $coin[501] );
check( 'a push queued since 3.28.0 - a website sale - sends and reads nothing either, and writes nothing', $stock['direct'] ?? null, array( 'wire' => array(), 'meta' => $coin[501], 'queries' => 0 ) );
check( 'one warning, no errors', array( $stock['warnings'] ?? null, $stock['errors'] ?? null ), array( 1, 0 ) );

// Belt and braces: should a refusal ever reach the push's own handling - from
// a guard further down - it is still not taken for an answer from eBay.
$answers = array(
	'refused'        => array( 'staging_blocked', 'Not sent to eBay: this site looks like a staging or development copy (WP_ENVIRONMENT_TYPE is staging).' ),
	'already closed' => array( 'api_error', 'The auction has already been closed.', array( 'Ack' => 'Failure', 'Errors' => array( 'ErrorCode' => '1047', 'ShortMessage' => 'The auction has already been closed.' ) ) ),
);
// The coin is fixed-price as eBay said on import: since 3.28.0 an EndItem for
// a listing whose type eBay has not confirmed reads the listing first (an
// auction ended that way loses its bids), which is not what is tested here.
$fixed    = array( 501 => $coin[501] + array( '_ebay_listing_type' => 'FixedPriceItem', '_ebay_listing_type_item' => (string) $coin[501]['_ebay_item_id'] ) );
$answered = site( 'stock_answered', array( 'skus' => array( 'SKU-1' => 501 ), 'meta' => $fixed, 'answers' => $answers, 'options' => array( 'tcgiant_sync_ebay_settings' => array( 'access_token' => 'harness-access', 'refresh_token' => 'harness-refresh' ) ) ) );
check( 'an EndItem refused before sending leaves the listing Active and unsettled', $answered['runs']['refused'][0]['meta'] ?? null, $coin[501] );
check( '  as does a refused quantity update', $answered['runs']['refused'][3]['meta'] ?? null, $coin[501] );
check( '  neither is tried again, nor eBay asked about the listing', array( $answered['runs']['refused'][0]['wire'] ?? null, $answered['runs']['refused'][0]['queued'] ?? null, $answered['runs']['refused'][3]['queued'] ?? null ), array( array( 'EndItem (answered by the harness)' ), array(), array() ) );
// Control. Until 3.28.0, eBay's words "already been closed" marked the
// listing Ended and settled. Now eBay is asked what the listing is: this one
// is still live, so it stays Active and the push is tried again. (Asked and
// over, it is settled: test-stock-push.php.)
$closed = $answered['runs']['already closed'][0] ?? array();
check( 'control: eBay\'s "already been closed" is not taken at its word - eBay is asked', in_array( 'GetItem (answered by the harness)', $closed['wire'] ?? array(), true ), true );
check( '  live, so still Active and unsettled, and tried again later', array( $closed['meta'] ?? null, $closed['queued'] ?? null ), array( $coin[501], array( 'tcgiant_sync_push_stock' ) ) );
check( '  so too for a quantity update', array( $answered['runs']['already closed'][3]['meta'] ?? null, $answered['runs']['already closed'][3]['queued'] ?? null ), array( $coin[501], array( 'tcgiant_sync_push_stock' ) ) );

echo "\nON A COPY, AUTO-RELIST, BULK JOBS AND TRACKING STOP AT THE DOOR\n" . str_repeat( '=', 132 ) . "\n";

$relist = site( 'auto_relist', array(
	'home'       => 'https://staging.numismax.com',
	'options'    => array( 'tcgiant_sync_ebay_settings' => array( 'auto_relist_enabled' => 1 ) ),
	'ended_rows' => array( array( 'product_id' => '501', 'ebay_item_id' => '110000000501' ) ),
	'meta'       => array( 501 => array( '_ebay_item_id' => '110000000501', '_ebay_listing_status' => 'Ended', '_stock' => '3', '_manage_stock' => 'yes' ) ),
) );
check( 'auto-relist asks eBay nothing - no GetItem, no relist', $relist['wire'] ?? null, array() );
check( '  and does not even read the ended listings', $relist['queries'] ?? null, 0 );
check( '  leaving the products as they were', $relist['meta'][501]['_ebay_listing_status'] ?? null, 'Ended' );
check( '  and says so, once', $relist['warnings'] ?? null, 1 );

$jobs  = site( 'jobs', array( 'home' => 'https://staging.numismax.com' ) );
$no    = 'Not sent to eBay: this site looks like a staging or development copy (its address has a "staging" subdomain).';
check( 'bulk End is refused whole, with the reason', $jobs['jobs']['bulk_end'] ?? null, array( 'success' => false, 'message' => $no, 'job' => false ) );
check( 'bulk Push is refused whole', $jobs['jobs']['bulk_push'] ?? null, array( 'success' => false, 'message' => $no, 'job' => false ) );
check( 'bulk Relist is refused whole', $jobs['jobs']['bulk_relist'] ?? null, array( 'success' => false, 'message' => $no, 'job' => false ) );
check( 'bulk Verify, which only asks eBay to check, is accepted', $jobs['jobs']['bulk_verify']['job'] ?? null, true );
check( 'a Stock Review job, which only changes this site, is accepted', $jobs['jobs']['bulk_keep_stock']['job'] ?? null, true );
check( 'nothing reached eBay', $jobs['wire'] ?? null, array() );
$jobs = site( 'jobs' );
check( 'control: on the live shop bulk End is accepted', $jobs['jobs']['bulk_end']['job'] ?? null, true );

// Item 11's refusal of a bulk push on a copy, beside 3.27.4's bulk push,
// which writes its batch's figures and marks each product before scheduling.
$coins = array( 501 => array( '_ebay_export_status' => 'pushed' ), 502 => array( '_ebay_item_id' => '110000000502' ) );
$bulk  = site( 'bulk_push_products', array( 'home' => 'https://staging.numismax.com', 'meta' => $coins ) );
check( 'the products list\'s bulk Push on a copy: nothing queued', $bulk['queued'] ?? null, 0 );
check( '  no batch begun: its figures not written', $bulk['state'] ?? null, false );
check( '  no product marked as queued', $bulk['meta'] ?? null, $coins );
check( '  nothing reached eBay, and it says so once', array( $bulk['wire'] ?? null, $bulk['warnings'] ?? null ), array( array(), 1 ) );

$track = site( 'tracking', array( 'home' => 'https://staging.numismax.com' ) );
check( 'a completed order on a copy does not mark the buyer\'s order shipped', $track['wire'] ?? null, array() );
check( '  nor note that it did', $track['notes'] ?? null, array() );
check( '  two orders, one warning, no errors', array( $track['warnings'] ?? null, $track['errors'] ?? null ), array( 1, 0 ) );
$track = site( 'tracking' );
check( 'control: the live shop sends CompleteSale', $track['wire'] ?? null, array( 'CompleteSale', 'CompleteSale' ) );

echo "\n\"THIS IS MY LIVE SHOP\" - THE REAL BUTTON\n" . str_repeat( '=', 132 ) . "\n";

// A live shop that happens to live at shop-dev.example.com.
$notice = site( 'notices', array( 'home' => 'https://shop-dev.example.com' ) );
$html   = (string) ( $notice['staging'] ?? '' );
check( 'it is kept off eBay by its address', $notice['reason'] ?? null, 'address:suffix:dev' );
check( '  and the notice says exactly what that means', false !== strpos( $html, 'Nothing is sent to eBay from this site: no stock, listing, relist or tracking changes. Reason: its address has a subdomain ending &quot;-dev&quot;.' ), true );
check( '  with the button, naming the address', false !== strpos( $html, 'This is my live shop - send changes to eBay from shop-dev.example.com' ), true );
check( '  which posts to its handler, with a nonce', false !== strpos( $html, 'value="tcgiant_confirm_live"' ) && false !== strpos( $html, 'nonce-tcgiant_confirm_live' ), true );

$pressed = site( 'confirm', array( 'home' => 'https://shop-dev.example.com' ) );
$record  = $pressed['record'] ?? array();
check( 'pressing it records this address, as a hash', $record['hash'] ?? null, hash( 'sha256', 'tcgiant-sync-live|shop-dev.example.com' ) );
check( '  with who pressed it', $record['user'] ?? null, 7 );
check( '  the log names them and the address', false !== strpos( json_encode( $pressed['log'] ?? array() ), 'coinshop-admin (user #7) confirmed that shop-dev.example.com is the live shop' ), true );
check( '  and the shop is sent back where it was', $pressed['sent']['redirect'] ?? null, 'https://site.invalid/wp-admin/admin.php?page=tcgiant-sync' );
check( '  the address now judges live', $pressed['after'] ?? null, '' );

$after = site( 'writes', array( 'home' => 'https://shop-dev.example.com', 'options' => $pressed['options'] ?? array(), 'skus' => array( 'SKU-1' => 501 ) ) );
check( 'the next request is live', array( $after['staging'] ?? null, $after['reason'] ?? null ), array( false, '' ) );
check( '  and its EndItem reaches eBay', $after['calls']['EndItem']['wire'] ?? null, array( 'EndItem' ) );

$moved = site( 'writes', array( 'home' => 'https://copy-dev.example.com', 'options' => $pressed['options'] ?? array() ) );
check( 'a copy of it at copy-dev.example.com is still a copy', $moved['reason'] ?? null, 'address:suffix:dev' );
check( '  and its EndItem goes nowhere', $moved['calls']['EndItem']['wire'] ?? null, array() );

$env = site( 'writes', array( 'home' => 'https://shop-dev.example.com', 'env' => 'staging', 'options' => $pressed['options'] ?? array() ) );
check( 'the confirmation does not overrule WP_ENVIRONMENT_TYPE staging', $env['reason'] ?? null, 'environment:staging' );

$env = site( 'confirm', array( 'home' => 'https://numismax.com', 'env' => 'staging' ) );
check( 'nor can the button be pressed against it', $env['record'] ?? 'nothing recorded', 'nothing recorded' );
$env = site( 'notices', array( 'home' => 'https://numismax.com', 'env' => 'staging' ) );
check( '  and that notice offers no button', false === strpos( (string) ( $env['staging'] ?? 'tcgiant_confirm_live' ), 'tcgiant_confirm_live' ), true );
check( '  but says where it is set', false !== strpos( (string) ( $env['staging'] ?? '' ), 'WP_ENVIRONMENT_TYPE' ), true );

$cfg = site( 'confirm', array( 'home' => 'https://shop-dev.example.com', 'defined' => true ) );
check( 'nor against TCGIANT_SYNC_IS_STAGING in wp-config.php', array( $cfg['reason'] ?? null, $cfg['record'] ?? 'nothing recorded' ), array( 'wp-config', 'nothing recorded' ) );
$cfg = site( 'writes', array( 'home' => 'https://shop-dev.example.com', 'defined' => true, 'options' => $pressed['options'] ?? array() ) );
check( '  which keeps the confirmed address off eBay too', $cfg['calls']['EndItem']['wire'] ?? null, array() );

$quiet = site( 'notices', array( 'home' => 'https://numismax.com' ) );
check( 'the live shop sees no staging notice', array( $quiet['staging'] ?? null, $quiet['changed'] ?? null ), array( '', '' ) );
$quiet = site( 'notices', array( 'home' => 'https://staging.numismax.com', 'screen' => 'dashboard' ) );
check( 'a copy sees its notice on the plugin\'s screens only', $quiet['staging'] ?? null, '' );

echo "\nUPDATING FROM 3.27: TELLING THE SHOPS WHOSE ANSWER CHANGED\n" . str_repeat( '=', 132 ) . "\n";

$connected = array( 'tcgiant_sync_ebay_settings' => array( 'refresh_token' => 'v^1.1#i^1' ) );

// A live shop 3.27 kept off eBay.
$up = site( 'verdict', array( 'home' => 'https://webdev-supplies.com', 'http_host' => 'webdev-supplies.com', 'options' => $connected ) );
check( 'webdev-supplies.com: 3.27 kept it off eBay, so it is told', $up['stored']['notice'] ?? null, 'now_live' );
check( '  the verdict is stored, live', array( $up['stored']['copy'] ?? null, $up['stored']['reason'] ?? null ), array( false, '' ) );
check( '  the notice says what happened and what to check', false !== strpos( (string) ( $up['changed'] ?? '' ), 'TCGiant Sync had been treating this shop as a staging copy and sent nothing to eBay. It now sends changes, but nothing from before has been sent' ) && false !== strpos( (string) ( $up['changed'] ?? '' ), 'Check the website orders from that time against your eBay listings' ), true );
// The weekly full sync went on reading eBay while nothing was sent, and put
// back in stock an item the website had sold: ending it on eBay alone would
// leave it for sale here.
check( '  and says to check the stock here too', false !== strpos( (string) ( $up['changed'] ?? '' ), 'set the stock here to what you hold' ), true );
// Stock Review cannot show what was missed, so it is not offered.
check( '  and no longer sends the shop to Stock Review for it', false === strpos( (string) ( $up['changed'] ?? '' ), 'page=tcgiant-stock-review' ), true );
check( '  it can be dismissed', false !== strpos( (string) ( $up['changed'] ?? '' ), 'action=tcgiant_dismiss_env_notice' ), true );
check( '  one line in the log, though the check ran twice', count( $up['log'] ?? array() ), 1 );
check( '  and nothing was pushed to eBay for it', array_key_exists( 'tcgiant_sync_jobs', $up['options'] ?? array() ), false );

$gone = site( 'dismiss', array( 'home' => 'https://webdev-supplies.com', 'options' => $up['options'] ?? array() ) );
check( 'dismissed, it stays dismissed', $gone['stored']['notice'] ?? null, '' );

$up = site( 'verdict', array( 'home' => 'https://webdev-supplies.com', 'http_host' => 'webdev-supplies.com' ) );
check( 'with no eBay account connected there is nothing to tell', $up['stored']['notice'] ?? null, '' );
$up = site( 'verdict', array( 'home' => 'https://staging.numismax.com', 'http_host' => 'staging.numismax.com', 'screen' => 'dashboard' ) );
check( '  nor the other way round - a fresh install on a copy is not "used to send"', $up['stored']['notice'] ?? null, '' );

// A copy 3.27 let through.
$up = site( 'verdict', array( 'home' => 'https://staging.numismax.com', 'http_host' => 'staging.numismax.com', 'options' => $connected, 'screen' => 'dashboard' ) );
check( 'staging.numismax.com: 3.27 sent from it, so its notice is louder', $up['stored']['notice'] ?? null, 'now_copy' );
check( '  shown on every admin screen, not only the plugin\'s', false !== strpos( (string) ( $up['staging'] ?? '' ), 'Until this update, TCGiant Sync did send changes to eBay from this site.' ), true );
check( '  with the button, in case it is in fact live', false !== strpos( (string) ( $up['staging'] ?? '' ), 'send changes to eBay from staging.numismax.com' ), true );
check( '  and a way to say it is a copy', false !== strpos( (string) ( $up['staging'] ?? '' ), 'This is a copy - show this only on TCGiant Sync screens' ), true );
check( '  logged once', count( $up['log'] ?? array() ), 1 );

$same = site( 'verdict', array( 'home' => 'https://numismax.com', 'http_host' => 'numismax.com', 'options' => $connected ) );
check( 'a shop both versions call live gets no notice, and no log line', array( $same['stored']['notice'] ?? null, count( $same['log'] ?? array( 1 ) ) ), array( '', 0 ) );

$cli = site( 'verdict', array( 'home' => 'https://numismax.com', 'options' => $connected ) );
check( 'with no host name to compare against, nothing is decided yet', $cli['stored'] ?? 'nothing', 'nothing' );

// Later: the verdict changes, and that is logged once.
$later = site( 'verdict', array( 'home' => 'https://dev.numismax.com', 'http_host' => 'dev.numismax.com', 'options' => $same['options'] ?? array() ) );
check( 'a later change of verdict is logged, once', count( $later['log'] ?? array() ), 1 );
check( '  saying what it was and what it is', false !== strpos( (string) ( $later['log'][0][1] ?? '' ), 'was judged the live shop and is now judged a staging copy' ), true );
check( '  and stored', array( $later['stored']['copy'] ?? null, $later['stored']['reason'] ?? null ), array( true, 'address:label:dev' ) );

echo "\nUNINSTALL KNOWS WHAT THIS STORES\n" . str_repeat( '=', 132 ) . "\n";

$env_src   = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-environment.php' );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
preg_match( '/\$tcgiant_options\s*=\s*array\((.*?)\);/s', $uninstall, $list );
preg_match_all( "/const\s+\w+_OPTION\s*=\s*'([a-z_]+)'/", $env_src, $opts );
check( 'the class stores two options', $opts[1], array( 'tcgiant_sync_env_verdict', 'tcgiant_sync_live_confirmed' ) );
$missing = array();
foreach ( $opts[1] as $name ) {
	if ( false === strpos( $list[1] ?? '', "'" . $name . "'" ) ) {
		$missing[] = $name;
	}
}
check( 'every one of them is on uninstall\'s list', $missing, array() );
preg_match( "/const\s+BLOCKED_TRANSIENT\s*=\s*'([a-z_]+)'/", $env_src, $transient );
check( 'its transient is one uninstall\'s tcgiant_ sweep removes', 0 === strpos( $transient[1] ?? '', 'tcgiant_' ) && false !== strpos( $uninstall, "LIKE '\\_transient\\_tcgiant\\_%'" ), true );

// --- tidy up ------------------------------------------------------------------
foreach ( array_merge( glob( "$WORLD/*" ) ?: array(), glob( "$WORLD/old/*" ) ?: array() ) as $f ) {
	if ( is_file( $f ) ) {
		unlink( $f );
	}
}
@rmdir( "$WORLD/old" );
@rmdir( $WORLD );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
