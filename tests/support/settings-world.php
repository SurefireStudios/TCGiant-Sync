<?php
/**
 * A played WordPress for the Settings harnesses (3.28.0).
 *
 * The hooks are WordPress's own (tests/wordpress/plugin.php). The options
 * behave as WordPress's do where a settings save depends on it:
 *
 *  - register_setting() adds its sanitize_callback as the
 *    sanitize_option_{$option} filter, and update_option() and add_option()
 *    both run sanitize_option();
 *  - update_option() returns false, writing nothing, when the value is
 *    unchanged, and passes an option that does not exist yet to
 *    add_option(), which sanitises it a second time;
 *  - add_option() refuses an option that exists, and its INSERT ... ON
 *    DUPLICATE KEY UPDATE reports no row changed - false - for an identical
 *    row that a request with an older view of the options inserts again
 *    ($GLOBALS['W']['stale'][ $option ] plays that view);
 *  - update_option() writing a row identical to the stored one changes no
 *    row, and is false, whatever this request's copy says;
 *  - options.php (options_php_save()) unslashes the posted value and hands
 *    it to update_option(), and $_POST is slashed, as wp_magic_quotes() leaves
 *    it;
 *  - in_one_request() plays one request's copy of the options: WordPress reads
 *    them once a request (alloptions), so a write made by another request
 *    (other_request_saves()) is not seen until the next one - but a read of
 *    the row through $wpdb sees it, and wp_cache_delete( 'alloptions' )
 *    reloads the copy. Outside in_one_request() every read sees every write, as
 *    before.
 *
 * Loaded for real: the catalogue, the API class (its constants; nothing here
 * reaches eBay), the site filter and the inventory class (the push switch the
 * Settings view reads). The harness requires the admin class itself, and
 * plays what the Settings view prints with.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__, 2 );

define( 'ABSPATH', $ROOT . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'TCGIANT_SYNC_PATH', $ROOT . '/' );
define( 'TCGIANT_SYNC_URL', 'https://coinshop.example.com/wp-content/plugins/tcgiant-sync/' );
if ( ! defined( 'TCGIANT_SYNC_VERSION' ) ) {
	define( 'TCGIANT_SYNC_VERSION', '3.28.0' );
}

require $ROOT . '/tests/wordpress/plugin.php';

$GLOBALS['W'] = array(
	'options'    => array(),
	'stale'      => array(),   // option => true: this request read it before another request wrote it.
	'autoload'   => array(),
	'transients' => array(),
	'meta'       => array(),
	'log'        => array(),
	'caps'       => array(),
	'user_can'   => true,
	'home'       => 'https://coinshop.example.com',   // A live shop's address: shop.example would be a copy.
	'registered' => array(),   // option group => options, from register_setting().
	'cache'      => null,      // This request's copy of the options (in_one_request()), or null.
);

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function map_deep( $value, $callback ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = map_deep( $v, $callback );
		}
		return $value;
	}
	return call_user_func( $callback, $value );
}
function maybe_serialize( $data ) { return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data; }
function is_serialized( $data ) { return is_string( $data ) && ( 'N;' === $data || (bool) preg_match( '/^[aOsibd]:/', $data ) ); }
function maybe_unserialize( $data ) { return is_serialized( $data ) ? unserialize( $data ) : $data; }

// --- options, as WordPress keeps them ----------------------------------------

function register_setting( $group, $name, $args = array() ) {
	if ( is_callable( $args ) ) {
		$args = array( 'sanitize_callback' => $args );
	}
	if ( ! empty( $args['sanitize_callback'] ) ) {
		add_filter( "sanitize_option_{$name}", $args['sanitize_callback'] );
	}
	$GLOBALS['W']['registered'][ $group ][] = $name;
}
function sanitize_option( $option, $value ) {
	return apply_filters( "sanitize_option_{$option}", $value, $option, $value );
}
function get_option( $option, $default = false ) {
	$pre = apply_filters( "pre_option_{$option}", false, $option, $default );
	if ( false !== $pre ) {
		return $pre;
	}
	$seen = null !== $GLOBALS['W']['cache'] ? $GLOBALS['W']['cache'] : $GLOBALS['W']['options'];
	if ( ! empty( $GLOBALS['W']['stale'][ $option ] ) || ! array_key_exists( $option, $seen ) ) {
		return apply_filters( "default_option_{$option}", $default, $option, false );
	}
	return apply_filters( "option_{$option}", $seen[ $option ], $option );
}
/** Write the row - and this request's copy, as WordPress updates its own cache on its own writes. */
function settings_world_store( $option, $value ) {
	$GLOBALS['W']['options'][ $option ] = $value;
	if ( null !== $GLOBALS['W']['cache'] ) {
		$GLOBALS['W']['cache'][ $option ] = $value;
	}
}
/** Run $fn as one request, with its own copy of the options taken as it begins. */
function in_one_request( callable $fn ) {
	$outer                 = $GLOBALS['W']['cache'];
	$GLOBALS['W']['cache'] = $GLOBALS['W']['options'];
	try {
		return $fn();
	} finally {
		$GLOBALS['W']['cache'] = $outer;
	}
}
/** Another request saves one key of the settings: the row changes, this request's copy does not. */
function other_request_saves( $key, $value ) {
	$s = $GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] ?? array();
	if ( null === $value ) {
		unset( $s[ $key ] );
	} else {
		$s[ $key ] = $value;
	}
	$GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] = $s;
}
/** Whether WordPress keeps an option among the autoloaded (alloptions) rather than in a cache entry of its own. */
function settings_world_autoloaded( $option ) {
	return ! in_array( $GLOBALS['W']['autoload'][ $option ] ?? 'yes', array( 'no', 'off', false ), true );
}
function wp_cache_delete( $key, $group = '' ) {
	if ( 'options' !== $group || null === $GLOBALS['W']['cache'] ) {
		return true;
	}
	foreach ( $GLOBALS['W']['options'] as $option => $value ) {
		// alloptions reloads the autoloaded ones; a key, its own entry.
		if ( ( 'alloptions' === $key && settings_world_autoloaded( $option ) ) || ( $key === $option && ! settings_world_autoloaded( $option ) ) ) {
			$GLOBALS['W']['cache'][ $option ] = $value;
		}
	}
	return true;
}
function update_option( $option, $value, $autoload = null ) {
	$value     = sanitize_option( $option, $value );
	$old_value = get_option( $option );
	$value     = apply_filters( "pre_update_option_{$option}", $value, $old_value, $option );
	$value     = apply_filters( 'pre_update_option', $value, $option, $old_value );

	if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
		return false;
	}
	if ( apply_filters( "default_option_{$option}", false, $option, false ) === $old_value ) {
		return add_option( $option, $value, '', null === $autoload ? 'yes' : $autoload );
	}

	// UPDATE ... of a row to what it already holds: no row changed, false.
	if ( array_key_exists( $option, $GLOBALS['W']['options'] ) && maybe_serialize( $GLOBALS['W']['options'][ $option ] ) === maybe_serialize( $value ) ) {
		return false;
	}

	settings_world_store( $option, $value );
	if ( null !== $autoload ) {
		$GLOBALS['W']['autoload'][ $option ] = $autoload;
	}
	do_action( "update_option_{$option}", $old_value, $value, $option );
	do_action( 'updated_option', $option, $old_value, $value );
	return true;
}
function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
	$value = sanitize_option( $option, $value );

	if ( apply_filters( "default_option_{$option}", false, $option, false ) !== get_option( $option ) ) {
		return false;
	}

	// INSERT ... ON DUPLICATE KEY UPDATE: the same row again changes nothing,
	// and MySQL reports 0 rows affected.
	if ( array_key_exists( $option, $GLOBALS['W']['options'] ) && maybe_serialize( $GLOBALS['W']['options'][ $option ] ) === maybe_serialize( $value ) ) {
		return false;
	}

	settings_world_store( $option, $value );
	$GLOBALS['W']['autoload'][ $option ] = $autoload;
	do_action( "add_option_{$option}", $option, $value );
	do_action( 'added_option', $option, $value );
	return true;
}
function delete_option( $option ) {
	if ( ! array_key_exists( $option, $GLOBALS['W']['options'] ) ) {
		return false;
	}
	unset( $GLOBALS['W']['options'][ $option ], $GLOBALS['W']['autoload'][ $option ] );
	if ( null !== $GLOBALS['W']['cache'] ) {
		unset( $GLOBALS['W']['cache'][ $option ] );
	}
	return true;
}

/**
 * wp-admin/options.php saving one form: the nonce and the capability are
 * WordPress's to check; then each option of the posted group, unslashed, is
 * handed to update_option() - null for one the form did not send.
 */
function options_php_save() {
	$group = isset( $_POST['option_page'] ) ? (string) $_POST['option_page'] : '';
	foreach ( $GLOBALS['W']['registered'][ $group ] ?? array() as $option ) {
		$value = null;
		if ( isset( $_POST[ $option ] ) ) {
			$value = $_POST[ $option ];
			if ( ! is_array( $value ) ) {
				$value = trim( $value );
			}
			$value = wp_unslash( $value );
		}
		update_option( $option, $value );
	}
}

function get_transient( $name ) { return $GLOBALS['W']['transients'][ $name ] ?? false; }
function set_transient( $name, $value, $expiry = 0 ) { $GLOBALS['W']['transients'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['W']['transients'][ $name ] ); return true; }

function get_post_meta( $id, $key = '', $single = false ) {
	$all = $GLOBALS['W']['meta'][ (int) $id ] ?? array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['W']['meta'][ (int) $id ][ $key ] = is_scalar( $value ) ? (string) $value : $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['W']['meta'][ (int) $id ][ $key ] );
	return true;
}

/** The database, as far as the Settings screen asks it: an option's row, and nothing else found. */
class Settings_World_DB {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $options  = 'wp_options';
	public $prefix   = 'wp_';
	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$v = $args[ $i++ ] ?? '';
			return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $sql );
	}
	public function get_col( $query ) { return array(); }
	public function get_var( $query ) { return settings_world_row( $query ); }
	public function get_results( $query, $output = null ) { return array(); }
	public function query( $query ) {
		$rows = settings_world_sql( $query );
		return null === $rows ? 0 : $rows;
	}
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
}
$GLOBALS['wpdb'] = new Settings_World_DB();

/**
 * An option's row written straight to the table, as a plugin does where only
 * one request may win (3.28.0, item R: the weekly stock check's place): a
 * plain INSERT, refused when the row exists; an UPDATE or a DELETE only where
 * the row still holds exactly the value given - or, without that condition,
 * whatever it holds. Rows changed, as MySQL counts them (a row given the value
 * it holds is not), false for a refused INSERT; null for any other statement,
 * which the caller answers.
 */
function settings_world_sql( $query ) {
	$q = "'((?:[^'\\\\]|\\\\.)*)'";
	if ( preg_match( "/^\\s*INSERT INTO wp_options \\( option_name, option_value, autoload \\) VALUES \\( $q, $q, $q \\)\\s*$/s", (string) $query, $m ) ) {
		$name = stripslashes( $m[1] );
		if ( array_key_exists( $name, $GLOBALS['W']['options'] ) ) {
			return false;   // Duplicate entry for key 'option_name'.
		}
		$GLOBALS['W']['options'][ $name ]  = maybe_unserialize( stripslashes( $m[2] ) );
		$GLOBALS['W']['autoload'][ $name ] = stripslashes( $m[3] );
		return 1;
	}
	if ( preg_match( "/^\\s*UPDATE wp_options SET option_value = $q WHERE option_name = $q AND option_value = $q\\s*$/s", (string) $query, $m ) ) {
		$name = stripslashes( $m[2] );
		if ( ! array_key_exists( $name, $GLOBALS['W']['options'] ) || maybe_serialize( $GLOBALS['W']['options'][ $name ] ) !== stripslashes( $m[3] ) || stripslashes( $m[1] ) === stripslashes( $m[3] ) ) {
			return 0;
		}
		$GLOBALS['W']['options'][ $name ] = maybe_unserialize( stripslashes( $m[1] ) );
		return 1;
	}
	if ( preg_match( "/^\\s*UPDATE wp_options SET option_value = $q WHERE option_name = $q\\s*$/s", (string) $query, $m ) ) {
		$name = stripslashes( $m[2] );
		if ( ! array_key_exists( $name, $GLOBALS['W']['options'] ) || maybe_serialize( $GLOBALS['W']['options'][ $name ] ) === stripslashes( $m[1] ) ) {
			return 0;
		}
		$GLOBALS['W']['options'][ $name ] = maybe_unserialize( stripslashes( $m[1] ) );
		return 1;
	}
	if ( preg_match( "/^\\s*DELETE FROM wp_options WHERE option_name = $q AND option_value = $q\\s*$/s", (string) $query, $m ) ) {
		$name = stripslashes( $m[1] );
		if ( ! array_key_exists( $name, $GLOBALS['W']['options'] ) || maybe_serialize( $GLOBALS['W']['options'][ $name ] ) !== stripslashes( $m[2] ) ) {
			return 0;
		}
		unset( $GLOBALS['W']['options'][ $name ], $GLOBALS['W']['autoload'][ $name ] );
		return 1;
	}
	return null;
}

/** An option's row, as the database holds it - never this request's copy. Null when the query is not that. */
function settings_world_row( $query ) {
	// $W['row_unreadable']: the database gives nothing back, as on an error.
	if ( ! empty( $GLOBALS['W']['row_unreadable'] ) || ! preg_match( "/SELECT option_value FROM wp_options WHERE option_name = '([^']+)'/", (string) $query, $m ) ) {
		return null;
	}
	// Counted, so that a harness can tell which reads went to the database.
	$GLOBALS['W']['row_reads'][ $m[1] ] = ( $GLOBALS['W']['row_reads'][ $m[1] ] ?? 0 ) + 1;
	return array_key_exists( $m[1], $GLOBALS['W']['options'] ) ? maybe_serialize( $GLOBALS['W']['options'][ $m[1] ] ) : null;
}

// --- formatting, i18n and the request ----------------------------------------

function __( $text, $domain = null ) { return $text; }
function _x( $text, $context, $domain = null ) { return $text; }
function _n( $single, $plural, $n, $domain = null ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_attr( $text ); }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return str_replace( array( '"', "'", '<', '>', ' ' ), array( '%22', '%27', '%3C', '%3E', '%20' ), (string) $url ); }
function esc_js( $text ) { return addslashes( (string) $text ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
// As WordPress's: tags, line breaks, tabs and %XX octets go, spaces collapse.
function sanitize_text_field( $text ) {
	$text = strip_tags( (string) $text );
	$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	$text = preg_replace( '/%[a-f0-9]{2}/i', '', $text );
	return trim( $text );
}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function absint( $n ) { return abs( (int) $n ); }
function home_url( $path = '' ) { return $GLOBALS['W']['home'] . $path; }
function get_site_url() { return $GLOBALS['W']['home']; }
function admin_url( $path = '' ) { return $GLOBALS['W']['home'] . '/wp-admin/' . $path; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function current_time( $type = 'mysql', $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function current_user_can( $cap, ...$args ) {
	if ( isset( $GLOBALS['W']['caps'][ $cap ] ) ) {
		return (bool) $GLOBALS['W']['caps'][ $cap ];
	}
	return (bool) $GLOBALS['W']['user_can'];
}
function get_current_user_id() { return 1; }
function wp_get_environment_type() { return 'production'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }

// --- the plugin's neighbours --------------------------------------------------

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['W']['log'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
	public static function write_now( $message, $level = 'error' ) { self::log( $message, $level ); }
	public static function flush() {}
}

/**
 * The connection, as the plugin reads it: from the stored option, unless a
 * harness sets what it holds ($settings) or whether it counts as connected
 * ($connected). A harness that sets $GLOBALS['TCG_REAL_OAUTH'] gets the real
 * class instead (its relay calls answered by the harness's wp_remote_post()).
 */
if ( empty( $GLOBALS['TCG_REAL_OAUTH'] ) ) :
class TCGiant_Sync_OAuth {
	const STATE_TRANSIENT = 'tcgiant_oauth_state';
	public static $settings  = null;
	public static $connected = null;
	public static function instance() { static $i; return $i ? $i : ( $i = new self() ); }
	public function get_settings() {
		return is_array( self::$settings ) ? self::$settings : get_option( 'tcgiant_sync_ebay_settings', array() );
	}
	public function is_authenticated() {
		if ( null !== self::$connected ) {
			return (bool) self::$connected;
		}
		$s = $this->get_settings();
		return ! empty( $s['access_token'] ) && ! empty( $s['refresh_token'] );
	}
	public function get_authorization_url() { return 'https://auth.ebay.example/connect'; }
	public function get_access_token() { return $this->is_authenticated() ? 'harness-token' : false; }
	public function refresh_access_token() { return false; }
	public static function user_agent_public() { return 'TCGiantSync/' . TCGIANT_SYNC_VERSION; }
}
else :
	require $ROOT . '/includes/class-tcgiant-sync-oauth.php';
endif;

require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
// A harness may load a copy of the class instead (its release switch set the
// other way, say), by naming it in $GLOBALS['TCG_INVENTORY_FILE'].
require $GLOBALS['TCG_INVENTORY_FILE'] ?? $ROOT . '/includes/class-tcgiant-sync-inventory.php';

// --- checks -------------------------------------------------------------------

$pass = 0;
$fail = 0;

// A harness that stops before done() - an exit in a handler, a fatal error
// part way - has not run its checks, so it must not pass.
$GLOBALS['harness_done'] = false;
register_shutdown_function( function () {
	if ( ! $GLOBALS['harness_done'] ) {
		echo "\n  FAIL  the harness stopped before its end: an exit or a fatal error part way\n\n";
		exit( 1 );
	}
} );
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-92s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
	if ( ! $ok ) {
		printf( "        want %s\n", substr( str_replace( "\n", ' ', var_export( $want, true ) ), 0, 400 ) );
	}
}
function done() {
	global $pass, $fail;
	$GLOBALS['harness_done'] = true;
	printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
