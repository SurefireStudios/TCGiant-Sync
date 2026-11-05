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
function update_option( $name, $value, $autoload = null ) { $GLOBALS['OPTIONS'][ $name ] = $value; $GLOBALS['AUTOLOAD'][ $name ] = $autoload; return true; }

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
if ( isset( $S['wp_home'] ) ) { define( 'WP_HOME', $S['wp_home'] ); }

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
function add_action( $hook = '', $cb = null ) { $GLOBALS['HOOKS'][] = array( $hook, is_array( $cb ) ? implode( '::', array_map( function ( $x ) { return is_object( $x ) ? get_class( $x ) : (string) $x; }, $cb ) ) : (string) $cb ); $GLOBALS['HOOK_CBS'][ $hook ][] = $cb; }
/** What WordPress does at that hook: run what was added to it. */
function run_hook( $hook ) { foreach ( $GLOBALS['HOOK_CBS'][ $hook ] ?? array() as $cb ) { if ( is_callable( $cb ) ) { call_user_func( $cb ); } } }
function add_filter() {}
function wp_mail( $to, $subject, $body ) { $GLOBALS['MAILS'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body ); return true; }
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
	public $prefix = 'wp_';
	public function __construct() { $this->prefix = $GLOBALS['S']['prefix'] ?? 'wp_'; }
	public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%s', "'%s'", $q ), $a ); }
	public function query( $q ) {
		$GLOBALS['QUERIES'][] = $q;
		if ( false !== strpos( $q, 'option_value + 1' ) ) { $GLOBALS['INCREMENTS']++; return 1; }
		// A row inserted only if it is not there, as MySQL's INSERT IGNORE.
		if ( preg_match( "/^INSERT IGNORE INTO wp_options \\( option_name, option_value, autoload \\) VALUES \\( '([^']+)', '([^']*)', 'no' \\)$/", trim( $q ), $m ) ) {
			if ( ! empty( $GLOBALS['S']['db_fails'] ) ) { return false; }
			// A table that lost its UNIQUE key on option_name takes every insert.
			if ( array_key_exists( $m[1], $GLOBALS['OPTIONS'] ) && empty( $GLOBALS['S']['no_unique'] ) ) { return 0; }
			$GLOBALS['OPTIONS'][ $m[1] ] = $m[2];
			return 1;
		}
		return 0;
	}
	public function get_var( $q ) {
		$GLOBALS['QUERIES'][] = $q;
		// The day's call count, read from the row it is counted in (3.28.0).
		if ( false !== strpos( $q, "'tcgiant_sync_api_calls_" ) ) { return (string) $GLOBALS['INCREMENTS']; }
		// Whether an option's row is there (fix round 3: the email's row).
		if ( preg_match( "/^SELECT COUNT\(\*\) FROM wp_options WHERE option_name = '([^']+)'$/", trim( $q ), $m ) ) {
			return ! empty( $GLOBALS['S']['db_read_fails'] ) ? null : ( array_key_exists( $m[1], $GLOBALS['OPTIONS'] ) ? '1' : '0' );
		}
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

// The logger comes with the plugin's autoloader, on plugins_loaded - after
// boot() (fix round 2: until then this harness loaded it first, so a line
// boot() wrote passed here and was lost on a real site). Declared inside a
// condition, so PHP cannot bind it early.
$LOGGER_AT_BOOT = class_exists( 'TCGiant_Sync_Logger', false );
if ( ! class_exists( 'TCGiant_Sync_Logger', false ) ) {
	class TCGiant_Sync_Logger {
		public static function log( $m, $level = 'info' ) { $GLOBALS['LOG'][] = array( $level, $m ); }
		public static function error( $m ) { self::log( $m, 'error' ); }
		public static function warning( $m ) { self::log( $m, 'warning' ); }
	}
}

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

	// A request of a site whose eBay connection is bound to an address
	// (3.31.0): its verdict, the list as it is after, and what reaches eBay.
	case 'binding':
		$calls = array(
			'EndItem'               => function () use ( $api ) { return $api->end_item( '110000000501' ); },
			'ReviseInventoryStatus' => function () use ( $api ) { return $api->update_trading_api_stock( '110000000501', 3 ); },
			'CompleteSale'          => function () use ( $api ) { return $api->trading_api_request( 'CompleteSale', '<OrderID>12-34567-89012</OrderID>' ); },
			'REST PUT'              => function () use ( $api ) { return $api->request( 'sell/inventory/v1/inventory_item/SKU-1', 'PUT', array( 'a' => 1 ) ); },
			'GetItem'               => function () use ( $api ) { return $api->get_item( '110000000501' ); },
			'GetOrders'             => function () use ( $api ) { return $api->trading_api_request( 'GetOrders', '<NumberOfDays>1</NumberOfDays>' ); },
			'GET inventory item'    => function () use ( $api ) { return $api->get_inventory_item( 'SKU-1' ); },
		);
		foreach ( $calls as $name => $fn ) {
			$GLOBALS['WIRE'] = array();
			$r = $fn();
			$R['calls'][ $name ] = array( 'outcome' => outcome( $r ), 'wire' => count( $GLOBALS['WIRE'] ) );
		}
		$R['bound']   = $GLOBALS['OPTIONS'][ TCGiant_Sync_Environment::BOUND_OPTION ] ?? null;
		$R['shown']   = TCGiant_Sync_Environment::bound_addresses();
		$R['options'] = $GLOBALS['OPTIONS'];
		$R['message'] = ( $b = TCGiant_Sync_Environment::write_block() ) ? $b->get_error_message() : '';
		$R['logger_at_boot'] = $GLOBALS['LOGGER_AT_BOOT'];
		$R['log_before_init'] = $GLOBALS['LOG'];
		run_hook( 'init' );
		$R['log']     = $GLOBALS['LOG'];
		$R['hooks']   = $GLOBALS['HOOKS'] ?? array();
		$R['options'] = $GLOBALS['OPTIONS'];
		break;

	// The administrator's email about a move (fix round 1), as init runs it
	// - twice, as two requests would.
	case 'mail':
		$R['hooks']   = $GLOBALS['HOOKS'] ?? array();
		$R['first']   = TCGiant_Sync_Environment::mail_move();
		$R['second']  = TCGiant_Sync_Environment::mail_move();
		$R['mails']   = $GLOBALS['MAILS'] ?? array();
		$R['options'] = $GLOBALS['OPTIONS'];
		$R['inserts'] = count( preg_grep( '/^\s*INSERT/', $GLOBALS['QUERIES'] ?? array() ) );
		break;

	// The eBay account connected at this address, as the OAuth callback
	// records it once the tokens are saved.
	case 'connect':
		$GLOBALS['OPTIONS']['tcgiant_sync_ebay_settings'] = array( 'refresh_token' => 'v^1.1#i^1#r^NEW' );
		$R['bound_to'] = TCGiant_Sync_Environment::bind( 'connect', 7 );
		$R['options']  = $GLOBALS['OPTIONS'];
		$R['after']    = TCGiant_Sync_Environment::copy_reason();
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

echo "\nTHE EBAY CONNECTION IS BOUND TO ITS ADDRESS (3.31.0)\n" . str_repeat( '=', 132 ) . "\n";

// A copy of the shop whose address no pattern recognises - a backup restored
// under another domain, a host's temporary address - was judged live by
// 3.28.0's check, and sent the live shop's stock changes, ends and tracking to
// its eBay account. Since 3.31.0 the connection belongs to the address it was
// made or confirmed at.
$connected = array( 'tcgiant_sync_ebay_settings' => array( 'refresh_token' => 'v^1.1#i^1#r^LIVE' ) );

$live = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $connected ) );
$list = $live['bound']['addresses'] ?? array();
$one  = $list ? reset( $list ) : array();
check( 'a shop connected before 3.31.0: live, and bound to its address at its first request', array( $live['reason'] ?? null, $live['shown'] ?? null, $one['how'] ?? null ), array( '', array( 'numismax.com' ), 'update' ) );
check( '  every change still reaches eBay', array( $live['calls']['EndItem']['wire'] ?? null, $live['calls']['REST PUT']['wire'] ?? null ), array( 1, 1 ) );
check( '  the record holds no address a search-and-replace could find', false === strpos( serialize( $live['bound'] ?? array() ), 'numismax' ), true );
$again = site( 'binding', array( 'home' => 'https://www.numismax.com/', 'options' => $live['options'] ?? array() ) );
check( '  www and a trailing slash are the same address: live, nothing added', array( $again['reason'] ?? null, count( $again['bound']['addresses'] ?? array() ) ), array( '', 1 ) );

// The live shop's database, at another address.
$copy = site( 'binding', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array() ) );
check( 'its database at numismax-copy.com, which no pattern catches: kept off eBay', array( $copy['staging'] ?? null, $copy['reason'] ?? null ), array( true, 'address:moved' ) );
foreach ( array( 'EndItem', 'ReviseInventoryStatus', 'CompleteSale', 'REST PUT' ) as $name ) {
	check( '  ' . $name . ' is refused, and nothing is sent', $copy['calls'][ $name ] ?? null, array( 'outcome' => 'staging_blocked', 'wire' => 0 ) );
}
foreach ( array( 'GetItem', 'GetOrders', 'GET inventory item' ) as $name ) {
	check( '  ' . $name . ' still reaches eBay', $copy['calls'][ $name ] ?? null, array( 'outcome' => 'sent', 'wire' => 1 ) );
}
check( '  the copy is not bound to itself', count( $copy['bound']['addresses'] ?? array() ), 1 );
check( '  the refusal says why, in words with nothing a stock push reads as "ended"', $copy['message'] ?? null, 'Not sent to eBay: this site looks like a staging or development copy (its address is not one its eBay connection was made or confirmed at).' );

// Every other write the plugin has, through the same harness as a copy's.
$all = site( 'writes', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array(), 'skus' => array( 'SKU-1' => 501 ) ) );
$sent_any = array();
foreach ( (array) ( $all['calls'] ?? array() ) as $name => $c ) {
	if ( 'staging_blocked' !== $c['outcome'] ) {
		$sent_any[] = $name;
	}
}
check( '  every one of the plugin\'s writes is refused there, Trading and REST alike', array( count( $all['calls'] ?? array() ), $sent_any ), array( 17, array() ) );
check( '  the Inventory API quantity update after its read (admin-ui_12)', $all['calls']['Inventory API quantity update (PUT)']['wire'] ?? null, array( 'GET sell/inventory/v1/inventory_item/SKU-1' ) );

// A migration tool rewrites the old domain wherever it finds it written out.
$migrated = unserialize( str_replace( 'numismax.com', 'numismax-copy.com', serialize( $live['options'] ?? array() ) ) );
$copy2    = site( 'binding', array( 'home' => 'https://numismax-copy.com', 'options' => $migrated ) );
check( 'after a search-and-replace of the domain through the database: still kept off eBay', $copy2['reason'] ?? null, 'address:moved' );

// The notice, and its one-click answer.
$notice = site( 'notices', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array(), 'screen' => 'dashboard' ) );
$html   = (string) ( $notice['staging'] ?? '' );
check( 'the notice is shown on every admin screen, not only the plugin\'s', '' !== $html, true );
check( '  it says where the connection was made and where the site is now', false !== strpos( $html, 'Its eBay connection was made at numismax.com, and this site is now at numismax-copy.com.' ), true );
check( '  and what that means', false !== strpos( $html, 'Until an administrator confirms this is the live shop, nothing is sent to eBay from here' ), true );
check( '  with the button, naming the new address, posting to the same handler as 3.28.0\'s', array( false !== strpos( $html, 'This is my live shop - send changes to eBay from numismax-copy.com' ), false !== strpos( $html, 'value="tcgiant_confirm_live"' ), false !== strpos( $html, 'nonce-tcgiant_confirm_live' ) ), array( true, true, true ) );
check( '  and says to leave it alone on a copy', false !== strpos( $html, 'If this site is a copy - a staging or test site, or a backup restored somewhere else - leave this alone' ), true );
$staff = site( 'notices', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array(), 'can' => false ) );
check( 'someone who cannot answer it sees the plain notice on the plugin\'s screens, with no button', array( false !== strpos( (string) ( $staff['staging'] ?? '' ), 'its address is not one its eBay connection was made or confirmed at' ), false === strpos( (string) ( $staff['staging'] ?? '' ), 'tcgiant_confirm_live' ) ), array( true, true ) );
$staff = site( 'notices', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array(), 'can' => false, 'screen' => 'dashboard' ) );
check( '  and nothing elsewhere', $staff['staging'] ?? null, '' );

$moved = site( 'confirm', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array() ) );
check( 'the shop really moved: one press, and the new address is live', $moved['after'] ?? null, '' );
check( '  the log names who and where', false !== strpos( json_encode( $moved['log'] ?? array() ), 'coinshop-admin (user #7) confirmed that numismax-copy.com is the live shop. It had looked like a staging copy (its address is not one its eBay connection was made or confirmed at)' ), true );
check( '  no pattern was overruled, so 3.28.0\'s record is not touched', array_key_exists( TCGiant_Sync_Environment::CONFIRMED_OPTION, $moved['options'] ?? array() ), false );
check( '  and the shop is told nothing went to eBay meanwhile', $moved['options'][ TCGiant_Sync_Environment::VERDICT_OPTION ]['notice'] ?? null, 'now_live' );
$after = site( 'binding', array( 'home' => 'https://numismax-copy.com', 'options' => $moved['options'] ?? array() ) );
check( '  the next request: EndItem reaches eBay', array( $after['reason'] ?? null, $after['calls']['EndItem']['wire'] ?? null ), array( '', 1 ) );
check( '  the list: both addresses, the newest first', $after['shown'] ?? null, array( 'numismax-copy.com', 'numismax.com' ) );
$both = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $moved['options'] ?? array() ) );
check( '  a shop served at both addresses is live at the old one too', $both['reason'] ?? null, '' );

// Moved to an address a pattern also catches: one press answers both.
$dev = site( 'confirm', array( 'home' => 'https://shop-dev.example.com', 'options' => $live['options'] ?? array() ) );
check( 'moved to shop-dev.example.com, which a pattern also catches: one press, live', array( $dev['after'] ?? null, isset( $dev['options'][ TCGiant_Sync_Environment::CONFIRMED_OPTION ] ), count( $dev['options'][ TCGiant_Sync_Environment::BOUND_OPTION ]['addresses'] ?? array() ) ), array( '', true, 2 ) );

// Connecting the account again at an address binds it.
$reconnect = site( 'connect', array( 'home' => 'https://numismax-copy.com', 'options' => $live['options'] ?? array() ) );
check( 'connecting eBay at numismax-copy.com binds it there', array( $reconnect['bound_to'] ?? null, $reconnect['after'] ?? null ), array( 'numismax-copy.com', '' ) );
// A fresh shop set up and connected on its staging copy at an address a
// pattern catches, then pushed to live: had the copy bound its own address,
// the live shop would be "moved" at once.
$fresh_copy = site( 'connect', array( 'home' => 'https://staging.numismax.com' ) );
check( '  but connecting eBay on a copy a pattern catches binds nothing', array( $fresh_copy['bound_to'] ?? null, isset( $fresh_copy['options'][ TCGiant_Sync_Environment::BOUND_OPTION ] ), $fresh_copy['after'] ?? null ), array( '', false, 'address:label:staging' ) );
$pushed = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $fresh_copy['options'] ?? array() ) );
check( '  so its database pushed to the live shop is live there, and bound to the live address', array( $pushed['reason'] ?? null, $pushed['shown'] ?? null ), array( '', array( 'numismax.com' ) ) );
$admin_src = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$saved_at  = strpos( $admin_src, 'if ( $oauth->save_tokens_from_relay( $data ) ) {' );
$bind_at   = strpos( $admin_src, "TCGiant_Sync_Environment::bind( 'connect', get_current_user_id() );" );
check( '  which the OAuth callback does once the tokens are saved, and only then', false !== $saved_at && false !== $bind_at && $bind_at > $saved_at && $bind_at - $saved_at < 400, true );

// What the binding never does.
$none = site( 'binding', array( 'home' => 'https://numismax-copy.com', 'options' => array( TCGiant_Sync_Environment::BOUND_OPTION => $live['bound'] ?? array() ) ) );
check( 'no eBay account connected: nothing to hold back, nothing decided', array( $none['reason'] ?? null, $none['calls']['EndItem']['outcome'] ?? null ), array( '', 'sent' ) );
$fresh = site( 'binding', array( 'home' => 'https://numismax.com' ) );
check( '  and nothing recorded', $fresh['bound'] ?? 'nothing', 'nothing' );
$pattern = site( 'binding', array( 'home' => 'https://staging.numismax.com', 'options' => $connected ) );
check( 'a copy a pattern catches is never bound to itself at the update', array( $pattern['reason'] ?? null, $pattern['bound'] ?? 'nothing' ), array( 'address:label:staging', 'nothing' ) );
$envcopy = site( 'binding', array( 'home' => 'https://numismax.com', 'env' => 'staging', 'options' => $connected ) );
check( '  nor one whose WP_ENVIRONMENT_TYPE is staging', array( $envcopy['reason'] ?? null, $envcopy['bound'] ?? 'nothing' ), array( 'environment:staging', 'nothing' ) );
$envmoved = site( 'binding', array( 'home' => 'https://numismax-copy.com', 'env' => 'staging', 'options' => $live['options'] ?? array() ) );
check( 'WP_ENVIRONMENT_TYPE still comes first', $envmoved['reason'] ?? null, 'environment:staging' );
$cfg = site( 'confirm', array( 'home' => 'https://numismax-copy.com', 'env' => 'staging', 'options' => $live['options'] ?? array() ) );
check( '  and the button cannot be pressed against it', array( $cfg['after'] ?? null, count( $cfg['options'][ TCGiant_Sync_Environment::BOUND_OPTION ]['addresses'] ?? array() ) ), array( 'environment:staging', 1 ) );
$v = plugin( array( 'home' => 'https://numismax-copy.com', 'defined' => false, 'options' => $live['options'] ?? array() ) );
check( 'TCGIANT_SYNC_IS_STAGING false in wp-config.php still lets a moved site send', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );

// The address it goes by is the stored one: never WP_HOME, never the
// request's host name. A wp-config.php that builds WP_HOME from the host a
// request arrived on would otherwise have every request on an IP address, or
// on whatever a bot put in its Host header, refuse a sale's stock push.
$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'http://203.0.113.9', 'http_host' => '203.0.113.9', 'options' => $live['options'] ?? array() ) );
check( 'a request on the server\'s IP address, WP_HOME built from it: live', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );
$v = plugin( array( 'home' => 'https://numismax.com', 'wp_home' => 'https://evil.example.net', 'http_host' => 'evil.example.net', 'options' => $live['options'] ?? array() ) );
check( '  on a made-up Host header: live', $v['staging'] ?? null, false );
// Bitnami's web request: WP_HOME built from the shop's own host name, the
// stored home only the install's placeholder - and that is what is bound.
$bitnami_bound = site( 'binding', array( 'home' => 'http://127.0.0.1', 'wp_home' => 'https://numismax.com', 'http_host' => 'numismax.com', 'options' => $connected ) );
check( 'Bitnami: its web request is live, and binds the stored placeholder', array( $bitnami_bound['reason'] ?? null, $bitnami_bound['shown'] ?? null ), array( '', array( '127.0.0.1' ) ) );
$v = plugin( array( 'home' => 'http://127.0.0.1', 'wp_home' => 'http://127.0.0.1/', 'http_host' => '127.0.0.1', 'options' => ( $bitnami_bound['options'] ?? array() ) + array( 'tcgiant_sync_env_verdict' => array( 'copy' => true, 'reason' => 'address:moved' ) ) ) );
check( 'Bitnami from WP-CLI, its stored home the install\'s placeholder: bound to it, so live, whatever the last page load said', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( false, '' ) );
$v = plugin( array( 'home' => 'https://numismax-copy.com', 'wp_home' => 'http://127.0.0.1/', 'http_host' => '127.0.0.1', 'options' => $live['options'] ?? array() ) );
check( 'a copy run from WP-CLI with a made-up WP_HOME: kept off eBay, by its stored address', array( $v['staging'] ?? null, $v['reason'] ?? null ), array( true, 'address:moved' ) );

echo "\nA STAGING COPY UPDATED FIRST, THEN PUSHED TO LIVE (FIX ROUND 1, H1)\n" . str_repeat( '=', 132 ) . "\n";

// A staging copy at a host's temporary address updates to 3.31.0 before the
// live shop and binds itself there; its database pushed to live carried that
// list over, and the live shop was "moved": every stock push refused, for
// good. Now a list holding only such addresses, bound at the update, is
// replaced with the live shop's.
$stagings = array(
	'Cloudways'            => 'https://wordpress-123456-789012.cloudwaysapps.com',
	'Flywheel'             => 'https://numismax.flywheelstaging.com',
	'Pressable'            => 'https://numismax.mystagingwebsite.com',
	'GoDaddy'              => 'https://abc.myftpupload.com',
	'WP Staging (folder)'  => 'https://numismax.com/mystage',
);
foreach ( $stagings as $host_name => $staging_url ) {
	// WP Staging's copy lives in the live shop's database under tables of its
	// own, wpstg0_ and on.
	$stg  = site( 'binding', array( 'home' => $staging_url, 'options' => $connected, 'prefix' => 0 === strpos( $host_name, 'WP Staging' ) ? 'wpstg0_' : 'wp_' ) );
	$live2 = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $stg['options'] ?? array() ) );
	$entry = $live2['bound']['addresses'] ?? array();
	$entry = $entry ? reset( $entry ) : array();
	check( sprintf( '%s: the copy, updated first, binds itself (as before)', $host_name ), array( $stg['reason'] ?? null, count( $stg['shown'] ?? array() ) ), array( '', 1 ) );
	check( '  pushed to live: live, bound to the live address only, and every write goes', array( $live2['reason'] ?? null, $live2['shown'] ?? null, $entry['how'] ?? null, $live2['calls']['EndItem']['wire'] ?? null, $live2['calls']['REST PUT']['wire'] ?? null ), array( '', array( 'numismax.com' ), 'staging-push', 1, 1 ) );
	check( '  the log says what happened - on init, once the logger has loaded (fix round 2)', array( $live2['logger_at_boot'] ?? null, false !== strpos( json_encode( $live2['log_before_init'] ?? array() ), 'which a staging copy recorded' ), false !== strpos( json_encode( $live2['log'] ?? array() ), 'which a staging copy recorded for itself when it was updated before this shop' ) ), array( false, false, true ) );
	check( '  and no email about a move', in_array( array( 'init', 'TCGiant_Sync_Environment::mail_move' ), $live2['hooks'] ?? array(), true ), false );
}

// What is still caught.
$after_push = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => site( 'binding', array( 'home' => $stagings['Cloudways'], 'options' => $connected ) )['options'] ?? array() ) );
$again      = site( 'binding', array( 'home' => $stagings['Cloudways'], 'options' => $after_push['options'] ?? array() ) );
check( 'after that push, a copy made again at the old staging address: kept off eBay', $again['reason'] ?? null, 'address:moved' );
foreach ( array( $stagings['Cloudways'], 'https://numismax-restore.com', 'https://numismax.com/mystage' ) as $later ) {
	$c = site( 'binding', array( 'home' => $later, 'options' => $live['options'] ?? array() ) );
	check( 'the live shop updated first, a copy made after at ' . $later . ': kept off eBay', $c['reason'] ?? null, 'address:moved' );
}
$own_domain = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => site( 'binding', array( 'home' => 'https://numismax-staging-copy.com', 'options' => $connected ) )['options'] ?? array() ) );
check( 'what cannot be told from a copy: a staging copy on a domain of the shop\'s own, updated first and pushed - moved, the button needed', $own_domain['reason'] ?? null, 'address:moved' );
$launched = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => site( 'connect', array( 'home' => $stagings['Cloudways'] ) )['options'] ?? array() ) );
check( 'a shop connected to eBay on its temporary address, then given its own domain: a real move, the button needed', $launched['reason'] ?? null, 'address:moved' );
$mixed = $connected;
$mixed[ TCGiant_Sync_Environment::BOUND_OPTION ] = array( 'addresses' => array_merge( ( site( 'binding', array( 'home' => $stagings['Flywheel'], 'options' => $connected ) )['bound']['addresses'] ?? array() ), ( $live['bound']['addresses'] ?? array() ) ) );
$m = site( 'binding', array( 'home' => 'https://numismax-copy.com', 'options' => $mixed ) );
check( 'a list holding a temporary address and the live shop\'s own: a copy elsewhere is still caught', array( $m['reason'] ?? null, count( $m['bound']['addresses'] ?? array() ) ), array( 'address:moved', 2 ) );

echo "\nTHE STAGING-PUSH RULE, NARROWED (FIX ROUND 2)\n" . str_repeat( '=', 132 ) . "\n";

// A shop that lives in a folder, bound at the update; its copy at the root of
// the domain. Until fix round 2 any folder of this address counted as a
// staging copy's, and this copy was let through - as was a real move out of
// a folder, put right without a word. Now only WP Staging's own copy counts.
$folder_shop = site( 'binding', array( 'home' => 'https://numismax.com/shop', 'options' => $connected ) );
$root_copy   = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $folder_shop['options'] ?? array() ) );
check( 'a shop in a folder, a copy at the root of its domain: kept off eBay', $root_copy['reason'] ?? null, 'address:moved' );
$wp_folder = site( 'binding', array( 'home' => 'https://numismax.com/wp', 'options' => $connected ) );
$moved_out = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $wp_folder['options'] ?? array() ) );
check( 'a real move out of a folder: the button, not put right silently', $moved_out['reason'] ?? null, 'address:moved' );
// A staging copy cloned again to another temporary address.
$first_stg   = site( 'binding', array( 'home' => $stagings['Cloudways'], 'options' => $connected ) );
$second_temp = site( 'binding', array( 'home' => 'https://numismax-two.flywheelsites.com', 'options' => $first_stg['options'] ?? array() ) );
check( 'a staging copy cloned again to another temporary address: kept off eBay', $second_temp['reason'] ?? null, 'address:moved' );
// What the database cannot tell: the same staging database copied to a
// domain of its own is the live shop's push, as far as anything here shows.
$second_real = site( 'binding', array( 'home' => 'https://numismax-dev-box.com', 'options' => $first_stg['options'] ?? array() ) );
check( 'what cannot be told from the live shop: that staging database copied to a domain of its own - live (said in the readme)', $second_real['reason'] ?? null, '' );
// Look-alikes (the spot check's survivors).
$lookalike = site( 'binding', array( 'home' => 'https://numismax.mykinsta.cloud', 'options' => $connected ) );
$after_la  = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $lookalike['options'] ?? array() ) );
check( 'mykinsta.cloud is not Kinsta\'s kinsta.cloud: its staging list is not put right', $after_la['reason'] ?? null, 'address:moved' );
$au_stg  = site( 'binding', array( 'home' => 'https://numismax.com.au', 'options' => $connected, 'prefix' => 'wpstg0_' ) );
$after_au = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $au_stg['options'] ?? array() ) );
check( 'numismax.com.au is not a folder of numismax.com, even with WP Staging\'s tables', $after_au['reason'] ?? null, 'address:moved' );
$wp_prefix = site( 'binding', array( 'home' => 'https://numismax.com/mystage', 'options' => $connected ) );
$after_wpp = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $wp_prefix['options'] ?? array() ) );
check( 'a folder bound with the shop\'s own tables, not WP Staging\'s: the button', $after_wpp['reason'] ?? null, 'address:moved' );
// Tables whose prefix holds "wpstg" without starting with it are not WP
// Staging's (the spot check's survivor K14, fix round 3).
$in_prefix = site( 'binding', array( 'home' => 'https://numismax.com/mystage', 'options' => $connected, 'prefix' => 'shop_wpstg0_' ) );
$after_in  = site( 'binding', array( 'home' => 'https://numismax.com', 'options' => $in_prefix['options'] ?? array() ) );
check( 'a folder bound with tables whose prefix only holds "wpstg": not WP Staging\'s, the button (fix round 3)', $after_in['reason'] ?? null, 'address:moved' );
// A live shop that itself lives on a host's temporary address, a staging copy
// at another pushed over it: not put right by itself - the button, as the
// readme now says (fix round 3, LOW-2; the readme said it was put right).
$tmp_live = site( 'binding', array( 'home' => 'https://numismax-live.flywheelsites.com', 'options' => site( 'binding', array( 'home' => $stagings['Flywheel'], 'options' => $connected ) )['options'] ?? array() ) );
check( 'a live shop on a temporary address, a staging copy pushed over it: the button, said in the readme (fix round 3)', $tmp_live['reason'] ?? null, 'address:moved' );
// And a copy of such a shop at a domain of its own: let through when the shop
// was bound by the update (connected before 3.31.0), caught when it connected
// after - both said in the readme (W8).
$tmp_shop = site( 'binding', array( 'home' => $stagings['Flywheel'], 'options' => $connected ) );
$tmp_copy = site( 'binding', array( 'home' => 'https://numismax-dev-box.com', 'options' => $tmp_shop['options'] ?? array() ) );
$tmp_conn = site( 'binding', array( 'home' => 'https://numismax-dev-box.com', 'options' => site( 'connect', array( 'home' => $stagings['Flywheel'] ) )['options'] ?? array() ) );
check( '  a copy of it at a domain of its own: live if the shop was bound by the update, kept off eBay if it connected after (readme)', array( $tmp_copy['reason'] ?? null, $tmp_conn['reason'] ?? null ), array( '', 'address:moved' ) );

echo "\nTHE ADMINISTRATOR IS EMAILED ABOUT A MOVE (FIX ROUND 1, OWNER Q5)\n" . str_repeat( '=', 132 ) . "\n";

$mail_opts                = $live['options'] ?? array();
$mail_opts['admin_email'] = 'owner@numismax.example';
$mail = site( 'mail', array( 'home' => 'https://numismax-copy.com', 'options' => $mail_opts ) );
$one  = $mail['mails'][0] ?? array();
check( 'a moved site hooks the email on init, as the plugin loads', in_array( array( 'init', 'TCGiant_Sync_Environment::mail_move' ), $mail['hooks'] ?? array(), true ), true );
check( '  one email, to the site\'s admin address, however many requests', array( count( $mail['mails'] ?? array() ), $one['to'] ?? null, $mail['first'] ?? null, $mail['second'] ?? null ), array( 1, 'owner@numismax.example', true, false ) );
check( '  naming the new address', $one['subject'] ?? null, 'TCGiant Sync is not sending changes to eBay from numismax-copy.com' );
check( '  saying where the connection was made, what to press, and that a copy needs nothing', array( false !== strpos( $one['body'] ?? '', 'its eBay connection was made at numismax.com, and this site is now at numismax-copy.com' ), false !== strpos( $one['body'] ?? '', 'press "This is my live shop"' ), false !== strpos( $one['body'] ?? '', 'nothing needs doing. Your live shop is not affected.' ), false !== strpos( $one['body'] ?? '', 'https://site.invalid/wp-admin/admin.php?page=tcgiant-sync' ) ), array( true, true, true, true ) );
$tcg_rows = array_values( array_filter( array_keys( $mail['options'] ?? array() ), function ( $k ) { return 0 === strpos( $k, TCGiant_Sync_Environment::MOVE_MAILED_PREFIX ); } ) );
check( '  remembered by a row of its own, named by a hash of the address (fix round 2)', array( count( $tcg_rows ), false === strpos( implode( ',', $tcg_rows ), 'numismax' ) ), array( 1, true ) );
$tcg_src = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-environment.php' );
check( '  inserted only if it is not there - INSERT IGNORE, the one request that inserts it sends', array( false !== strpos( $tcg_src, 'INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload )' ), false !== strpos( $tcg_src, 'if ( 1 !== (int) $claimed ) {' ) ), array( true, true ) );
$tcg_lost = site( 'mail', array( 'home' => 'https://numismax-copy.com', 'options' => $mail_opts, 'db_fails' => true ) );
check( 'a database that cannot write the row: no email, request after request - not one every request', array( count( $tcg_lost['mails'] ?? array() ), $tcg_lost['first'] ?? null, $tcg_lost['second'] ?? null ), array( 0, false, false ) );
check( '  and the email no longer says "once for each address" of a database copied again', array( false !== strpos( $one['body'] ?? '', 'once for each address it finds itself at; a copy made again from your live shop may send it again' ), false === strpos( $one['body'] ?? '', 'This email is sent once for each address.' ) ), array( true, true ) );
$next = site( 'mail', array( 'home' => 'https://numismax-copy.com', 'options' => $mail['options'] ?? array() ) );
check( 'the next request at that address: none', count( $next['mails'] ?? array() ), 0 );
check( '  and no write: the row is looked for first (fix round 3)', $next['inserts'] ?? null, 0 );
// An options table without its UNIQUE key on option_name - some migration
// tools drop indexes, and copies are made by exactly those tools - takes the
// INSERT every time: fix round 2 sent an email on every request (LOW-3).
$tcg_nokey = site( 'mail', array( 'home' => 'https://numismax-copy.com', 'options' => $mail_opts, 'no_unique' => true ) );
check( 'an options table that lost its UNIQUE key: still one email, not one every request (fix round 3)', array( count( $tcg_nokey['mails'] ?? array() ), $tcg_nokey['first'] ?? null, $tcg_nokey['second'] ?? null, $tcg_nokey['inserts'] ?? null ), array( 1, true, false, 1 ) );
$tcg_noread = site( 'mail', array( 'home' => 'https://numismax-copy.com', 'options' => $mail_opts, 'db_read_fails' => true ) );
check( '  a database that cannot say whether the row is there: no email, and no write', array( count( $tcg_noread['mails'] ?? array() ), $tcg_noread['inserts'] ?? null ), array( 0, 0 ) );
$other = site( 'mail', array( 'home' => 'https://numismax-second.com', 'options' => $mail['options'] ?? array() ) );
check( 'a copy at another address: one more', count( $other['mails'] ?? array() ), 1 );
$quiet = site( 'mail', array( 'home' => 'https://numismax.com', 'options' => $mail_opts ) );
check( 'the live shop at its own address: no hook, no email', array( in_array( 'init', array_column( $quiet['hooks'] ?? array(), 0 ), true ), count( $quiet['mails'] ?? array() ) ), array( false, 0 ) );
$patt = site( 'mail', array( 'home' => 'https://staging.numismax.com', 'options' => $mail_opts ) );
check( 'a copy a pattern catches, not a move: none', count( $patt['mails'] ?? array() ), 0 );
$env_src_now = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-environment.php' );
check( 'one hook to take out to stop them', substr_count( $env_src_now, "add_action( 'init', array( __CLASS__, 'mail_move' ) );" ), 1 );

echo "\nONE ADDRESS, HOWEVER IT IS WRITTEN (FIX ROUND 1, L2)\n" . str_repeat( '=', 132 ) . "\n";

// strtolower() before PHP 8.2 follows the locale: under a Latin-1 one it
// rewrote a UTF-8 address's bytes, so one address hashed two ways.
$tcg_locale = false;
foreach ( array( 'de_DE.ISO8859-1', 'de_DE.ISO-8859-1', 'de_DE', 'German_Germany.1252', 'German' ) as $tcg_try ) {
	if ( false !== setlocale( LC_CTYPE, $tcg_try ) ) {
		$tcg_locale = $tcg_try;
		break;
	}
}
$latin = TCGiant_Sync_Environment::address( "https://B\u{00FC}CHER.de/Shop" );
setlocale( LC_CTYPE, 'C' );
$plain = TCGiant_Sync_Environment::address( "https://B\u{00FC}CHER.de/Shop" );
check( 'an address with a non-ASCII letter: the same in a Latin-1 locale (' . ( $tcg_locale ? $tcg_locale : 'none available' ) . ') and in C', array( $latin, $plain ), array( "b\u{00FC}cher.de/shop", "b\u{00FC}cher.de/shop" ) );

// parse_url() under a single-byte locale (cp1252, ISO-8859-x; PHP 7.4 takes
// it from its environment) rewrites the bytes 0x80-0x9F as '_', which most
// non-Latin names hold: a Cyrillic home bound in one process was "moved" in
// the next (fix round 3, LOW-1). The host is now parsed with those bytes
// percent-encoded, and decoded after.
$tcg_cp = false;
foreach ( array( 'English_United States.1252', 'German_Germany.1252', 'en_US.ISO8859-1', 'en_US.ISO-8859-1', 'de_DE.ISO8859-1', 'de_DE.ISO-8859-1', 'de_DE' ) as $tcg_try ) {
	if ( false !== setlocale( LC_CTYPE, $tcg_try ) ) {
		$tcg_cp = $tcg_try;
		break;
	}
}
$tcg_ru     = "\u{0441}\u{0430}\u{0439}\u{0442}.\u{0440}\u{0444}";
$tcg_raw    = parse_url( 'https://' . $tcg_ru . '/' );
$tcg_single = array( TCGiant_Sync_Environment::address( 'https://' . $tcg_ru . '/Shop' ), TCGiant_Sync_Environment::address( 'https://www.' . $tcg_ru . ':8443/%D0%BC%41x/' ), TCGiant_Sync_Environment::address( "https://\u{1F600}smile.com" ) );
setlocale( LC_CTYPE, 'C' );
$tcg_c      = array( TCGiant_Sync_Environment::address( 'https://' . $tcg_ru . '/Shop' ), TCGiant_Sync_Environment::address( 'https://www.' . $tcg_ru . ':8443/%D0%BC%41x/' ), TCGiant_Sync_Environment::address( "https://\u{1F600}smile.com" ) );
$tcg_want   = array( $tcg_ru . '/shop', $tcg_ru . ':8443/%d0%bc%41x', "\u{1F600}smile.com" );
if ( $tcg_cp ) {
	check( 'in a single-byte locale (' . $tcg_cp . ') parse_url() itself rewrites the Cyrillic name: the fault', ( $tcg_raw['host'] ?? '' ) !== $tcg_ru, true );
} else {
	echo "  (no single-byte locale here to show parse_url()'s fault in; the checks below run in C)\n";
}
check( '  a Cyrillic name, with a port and an escaped folder, and an emoji name: the same there and in C (fix round 3)', array( $tcg_single, $tcg_c ), array( $tcg_want, $tcg_want ) );
$tcg_pa = new ReflectionMethod( 'TCGiant_Sync_Environment', 'parse' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_pa->setAccessible( true );
}
$tcg_pp = $tcg_pa->invoke( null, 'https://' . $tcg_ru . '/a%2Fb%25c' );
check( '  a \'%\' already in such a URL comes back as it was, not decoded', $tcg_pp['path'], '/a%2Fb%25c' );
check( '  and an ASCII URL is parsed exactly as before', $tcg_pa->invoke( null, 'https://Shop.Example.com:8080/a%2Fb' ), array( 'host' => 'shop.example.com', 'port' => 8080, 'path' => '/a%2Fb' ) );
$bh = new ReflectionMethod( 'TCGiant_Sync_Environment', 'bound_hash' );
if ( PHP_VERSION_ID < 80100 ) {
	$bh->setAccessible( true );
}
// One form, computed by the plugin itself, the same in every process: intl
// or not (fix round 2 - fix round 1 used intl, so a home bound where intl
// was loaded was "moved" where it was not).
check( 'bücher.de and xn--bcher-kva.de bind as one address, in this PHP (intl ' . ( extension_loaded( 'intl' ) ? 'loaded' : 'not loaded' ) . ')', $bh->invoke( null, "b\u{00FC}cher.de" ), $bh->invoke( null, 'xn--bcher-kva.de' ) );
check( '  so do bücher-münzen.de and xn--bcher-mnzen-thbg.de, with a port and a folder', $bh->invoke( null, "b\u{00FC}cher-m\u{00FC}nzen.de:8443/shop" ), $bh->invoke( null, 'xn--bcher-mnzen-thbg.de:8443/shop' ) );
check( '  the environment class never asks intl', false === strpos( (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-environment.php' ), 'idn_to_ascii(' ), true );
// And the form is intl's own, where a PHP that loads intl can say.
$tcg_idn = $WORLD . '/idn.php';
file_put_contents( $tcg_idn, '<?php define( "ABSPATH", __DIR__ . "/" ); function __( $t ) { return $t; } require ' . var_export( $ROOT . '/includes/class-tcgiant-sync-environment.php', true ) . '; $m = new ReflectionMethod( "TCGiant_Sync_Environment", "ascii_host" ); if ( PHP_VERSION_ID < 80100 ) { $m->setAccessible( true ); } $out = array(); foreach ( array( "b\\u{00FC}cher.de", "m\\u{00FC}nchen.de", "espa\\u{00F1}a.com", "\\u{4F8B}\\u{3048}.jp", "\\u{0441}\\u{0430}\\u{0439}\\u{0442}.\\u{0440}\\u{0444}", "stra\\u{00DF}e.de", "\\u{1F600}smile.com" ) as $h ) { $out[] = $m->invoke( null, $h ) === idn_to_ascii( $h, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 ); } echo json_encode( $out );' );
$tcg_out = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d extension=intl ' . escapeshellarg( $tcg_idn ) . ' 2>' . ( '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null' ) ), true );
@unlink( $tcg_idn );
if ( is_array( $tcg_out ) ) {
	check( '  and it is the form intl gives, for seven names in five scripts (intl loaded in a PHP of its own)', $tcg_out, array_fill( 0, 7, true ) );
} else {
	echo "  (PHP's intl extension cannot be loaded here to compare with: the form is RFC 3492's)\n";
}
check( 'an ASCII address hashes exactly as before fix round 1', $bh->invoke( null, 'numismax.com/shop' ), hash( 'sha256', 'tcgiant-sync-bound|numismax.com/shop' ) );

// RFC 3492's own samples (section 7.1; (S) is all ASCII, which is never
// encoded; the Russian without the RFC's optional case annotation), plus a
// 4-byte emoji. Long labels reach the bias adaptation a short name never
// does: the spot check broke it and every check above still passed (fix
// round 3, LOW-4).
$tcg_puny = new ReflectionMethod( 'TCGiant_Sync_Environment', 'punycode' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_puny->setAccessible( true );
}
$tcg_rfc = array(
	'A Arabic (Egyptian)' => array( "\u{0644}\u{064A}\u{0647}\u{0645}\u{0627}\u{0628}\u{062A}\u{0643}\u{0644}\u{0645}\u{0648}\u{0634}\u{0639}\u{0631}\u{0628}\u{064A}\u{061F}", 'egbpdaj6bu4bxfgehfvwxn' ),
	'B Chinese (simplified)' => array( "\u{4ED6}\u{4EEC}\u{4E3A}\u{4EC0}\u{4E48}\u{4E0D}\u{8BF4}\u{4E2D}\u{6587}", 'ihqwcrb4cv8a8dqg056pqjye' ),
	'C Chinese (traditional)' => array( "\u{4ED6}\u{5011}\u{7232}\u{4EC0}\u{9EBD}\u{4E0D}\u{8AAA}\u{4E2D}\u{6587}", 'ihqwctvzc91f659drss3x8bo0yb' ),
	'D Czech' => array( "Pro\u{010D}prost\u{011B}nemluv\u{00ED}\u{010D}esky", 'Proprostnemluvesky-uyb24dma41a' ),
	'E Hebrew' => array( "\u{05DC}\u{05DE}\u{05D4}\u{05D4}\u{05DD}\u{05E4}\u{05E9}\u{05D5}\u{05D8}\u{05DC}\u{05D0}\u{05DE}\u{05D3}\u{05D1}\u{05E8}\u{05D9}\u{05DD}\u{05E2}\u{05D1}\u{05E8}\u{05D9}\u{05EA}", '4dbcagdahymbxekheh6e0a7fei0b' ),
	'F Hindi' => array( "\u{092F}\u{0939}\u{0932}\u{094B}\u{0917}\u{0939}\u{093F}\u{0928}\u{094D}\u{0926}\u{0940}\u{0915}\u{094D}\u{092F}\u{094B}\u{0902}\u{0928}\u{0939}\u{0940}\u{0902}\u{092C}\u{094B}\u{0932}\u{0938}\u{0915}\u{0924}\u{0947}\u{0939}\u{0948}\u{0902}", 'i1baa7eci9glrd9b2ae1bj0hfcgg6iyaf8o0a1dig0cd' ),
	'G Japanese' => array( "\u{306A}\u{305C}\u{307F}\u{3093}\u{306A}\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{8A71}\u{3057}\u{3066}\u{304F}\u{308C}\u{306A}\u{3044}\u{306E}\u{304B}", 'n8jok5ay5dzabd5bym9f0cm5685rrjetr6pdxa' ),
	'H Korean' => array( "\u{C138}\u{ACC4}\u{C758}\u{BAA8}\u{B4E0}\u{C0AC}\u{B78C}\u{B4E4}\u{C774}\u{D55C}\u{AD6D}\u{C5B4}\u{B97C}\u{C774}\u{D574}\u{D55C}\u{B2E4}\u{BA74}\u{C5BC}\u{B9C8}\u{B098}\u{C88B}\u{C744}\u{AE4C}", '989aomsvi5e83db1d2a355cv1e0vak1dwrv93d5xbh15a0dt30a5jpsd879ccm6fea98c' ),
	'I Russian' => array( "\u{043F}\u{043E}\u{0447}\u{0435}\u{043C}\u{0443}\u{0436}\u{0435}\u{043E}\u{043D}\u{0438}\u{043D}\u{0435}\u{0433}\u{043E}\u{0432}\u{043E}\u{0440}\u{044F}\u{0442}\u{043F}\u{043E}\u{0440}\u{0443}\u{0441}\u{0441}\u{043A}\u{0438}", 'b1abfaaepdrnnbgefbadotcwatmq2g4l' ),
	'J Spanish' => array( "Porqu\u{00E9}nopuedensimplementehablarenEspa\u{00F1}ol", 'PorqunopuedensimplementehablarenEspaol-fmd56a' ),
	'K Vietnamese' => array( "T\u{1EA1}isaoh\u{1ECD}kh\u{00F4}ngth\u{1EC3}ch\u{1EC9}n\u{00F3}iti\u{1EBF}ngVi\u{1EC7}t", 'TisaohkhngthchnitingVit-kjcr8268qyxafd2f1b9g' ),
	'L' => array( "3\u{5E74}B\u{7D44}\u{91D1}\u{516B}\u{5148}\u{751F}", '3B-ww4c5e180e575a65lsy2b' ),
	'M' => array( "\u{5B89}\u{5BA4}\u{5948}\u{7F8E}\u{6075}-with-SUPER-MONKEYS", '-with-SUPER-MONKEYS-pc58ag80a8qai00g7n9n' ),
	'N' => array( "Hello-Another-Way-\u{305D}\u{308C}\u{305E}\u{308C}\u{306E}\u{5834}\u{6240}", 'Hello-Another-Way--fc4qua05auwb3674vfr0b' ),
	'O' => array( "\u{3072}\u{3068}\u{3064}\u{5C4B}\u{6839}\u{306E}\u{4E0B}2", '2-u9tlzr9756bt3uc0v' ),
	'P' => array( "Maji\u{3067}Koi\u{3059}\u{308B}5\u{79D2}\u{524D}", 'MajiKoi5-783gue6qz075azm5e' ),
	'Q' => array( "\u{30D1}\u{30D5}\u{30A3}\u{30FC}de\u{30EB}\u{30F3}\u{30D0}", 'de-jg4avhby1noc0d' ),
	'R' => array( "\u{305D}\u{306E}\u{30B9}\u{30D4}\u{30FC}\u{30C9}\u{3067}", 'd9juau41awczczp' ),
	'an emoji (4 bytes)' => array( "\u{1F600}", 'e28h' ),
);
$tcg_bad = array();
foreach ( $tcg_rfc as $tcg_k => $tcg_pair ) {
	$tcg_got = $tcg_puny->invoke( null, $tcg_pair[0] );
	if ( $tcg_got !== $tcg_pair[1] ) {
		$tcg_bad[ $tcg_k ] = $tcg_got;
	}
}
check( 'punycode() gives RFC 3492\'s 18 samples (A-R) and an emoji exactly, none wrong (fix round 3)', array( count( $tcg_rfc ), $tcg_bad ), array( 19, array() ) );
check( '  and refuses a label that is not UTF-8, so the address is compared as it is', $tcg_puny->invoke( null, "\xC3\x28" ), null );

// Ten addresses at most, the most recent first.
$GLOBALS['OPTIONS'] = $connected;
$GLOBALS['ENV_TYPE'] = 'production';
for ( $tcg_i = 1; $tcg_i <= 12; $tcg_i++ ) {
	$GLOBALS['HOME_URL'] = 'https://shop' . $tcg_i . '.example.org';
	TCGiant_Sync_Environment::bind( 'confirm', 7 );
}
$GLOBALS['HOME_URL'] = 'https://numismax.com';
check( 'the list keeps the ten most recent addresses, the newest first', TCGiant_Sync_Environment::bound_addresses(), array( 'shop12.example.org', 'shop11.example.org', 'shop10.example.org', 'shop9.example.org', 'shop8.example.org', 'shop7.example.org', 'shop6.example.org', 'shop5.example.org', 'shop4.example.org', 'shop3.example.org' ) );
check( '  autoloaded, as it is read on every request', $GLOBALS['AUTOLOAD'][ TCGiant_Sync_Environment::BOUND_OPTION ] ?? null, true );
$GLOBALS['OPTIONS'] = array();

echo "\nUNINSTALL KNOWS WHAT THIS STORES\n" . str_repeat( '=', 132 ) . "\n";

$env_src   = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-environment.php' );
$uninstall = (string) file_get_contents( $ROOT . '/uninstall.php' );
preg_match( '/\$tcgiant_options\s*=\s*array\((.*?)\);/s', $uninstall, $list );
preg_match_all( "/const\s+\w+_OPTION\s*=\s*'([a-z_]+)'/", $env_src, $opts );
check( 'the class stores three options', $opts[1], array( 'tcgiant_sync_env_verdict', 'tcgiant_sync_live_confirmed', 'tcgiant_sync_bound_address' ) );
check( '  and a row for each address emailed about, which uninstall\'s sweep removes', false !== strpos( $uninstall, "OR option_name LIKE 'tcgiant_sync_move_mailed_%'" ), true );
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
