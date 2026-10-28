<?php
/**
 * A played WordPress for the listing-template harnesses.
 *
 * Posts, post meta, options and transients are kept in memory. The filters
 * are WordPress's own (tests/wordpress/plugin.php) and so is kses
 * (tests/wordpress/kses.php), so what the sanitiser does with a tag, an
 * attribute or an inline style is what WordPress does, not a copy of it.
 *
 * Loaded for real: the template classes, the catalogue, the site filter's
 * helpers, the API class (with eBay's metadata played) and the exporter.
 * Products are played below, with as much of WC_Product as the exporter
 * reads.
 *
 * Each harness requires this, then builds its products and asserts.
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
define( 'TCGIANT_SYNC_URL', 'https://shop.example/wp-content/plugins/tcgiant-sync/' );
if ( ! defined( 'TCGIANT_SYNC_VERSION' ) ) {
	define( 'TCGIANT_SYNC_VERSION', '3.27.8' );
}

require $ROOT . '/tests/wordpress/plugin.php';

// WordPress's own polyfills for PHP 7.4 (wp-includes/compat.php), each only
// where PHP lacks it: kses.php calls them, and WordPress always loads these
// first, so on a real site the plugin's cleaning works on 7.4 too.
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}
		return false !== strpos( $haystack, $needle );
	}
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}
		return 0 === strpos( $haystack, $needle );
	}
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ) {
		if ( '' === $haystack ) {
			return '' === $needle;
		}
		$len = strlen( $needle );
		return substr( $haystack, -$len, $len ) === $needle;
	}
}

require $ROOT . '/tests/wordpress/kses.php';

// --- the store ---------------------------------------------------------------

$GLOBALS['W'] = array(
	'posts'      => array(),
	'meta'       => array(),
	'options'    => array(),
	'transients' => array(),
	'next_id'    => 1000,
	'log'        => array(),
	'home'       => 'https://shop.example',
	'images'     => array(),   // attachment ID => URL
	'terms'      => array(),   // term ID => array( parent, name )
	'term_names' => array(),   // taxonomy|slug => name
	'products'   => array(),
	'user_can'   => true,
);

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_title;
	public $post_name;
	public $post_content = '';
	public $post_modified;
	public $post_author = 0;
	public function __construct( array $data ) {
		foreach ( $data as $k => $v ) {
			$this->$k = $v;
		}
	}
}

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

function get_post( $id ) {
	return $GLOBALS['W']['posts'][ (int) $id ] ?? null;
}
function wp_insert_post( $data, $wp_error = false ) {
	$data = wp_unslash( $data );
	$id   = ++$GLOBALS['W']['next_id'];
	$GLOBALS['W']['posts'][ $id ] = new WP_Post( array(
		'ID'            => $id,
		'post_type'     => $data['post_type'] ?? 'post',
		'post_status'   => $data['post_status'] ?? 'draft',
		'post_title'    => $data['post_title'] ?? '',
		'post_name'     => $data['post_name'] ?? '',
		'post_modified' => gmdate( 'Y-m-d H:i:s' ),
		'post_author'   => $data['post_author'] ?? 0,
	) );
	return $id;
}
function wp_update_post( $data, $wp_error = false ) {
	$data = wp_unslash( $data );
	$post = get_post( $data['ID'] );
	if ( ! $post ) {
		return new WP_Error( 'invalid_post', 'No such post' );
	}
	foreach ( $data as $k => $v ) {
		if ( 'ID' !== $k ) {
			$post->$k = $v;
		}
	}
	$post->post_modified = gmdate( 'Y-m-d H:i:s' );
	return (int) $data['ID'];
}
function wp_delete_post( $id, $force = false ) {
	unset( $GLOBALS['W']['posts'][ (int) $id ], $GLOBALS['W']['meta'][ (int) $id ] );
	return true;
}
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['W']['posts'] as $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue;
		}
		$statuses = (array) ( $args['post_status'] ?? 'publish' );
		if ( ! in_array( 'any', $statuses, true ) && ! in_array( $post->post_status, $statuses, true ) ) {
			continue;
		}
		if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
			continue;
		}
		$out[] = ( 'ids' === ( $args['fields'] ?? '' ) ) ? $post->ID : $post;
	}
	$n = (int) ( $args['numberposts'] ?? 5 );
	return $n > 0 ? array_slice( $out, 0, $n ) : $out;
}
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
	// As WordPress does: the value is unslashed on the way in.
	$GLOBALS['W']['meta'][ (int) $id ][ $key ] = wp_unslash( $value );
	return true;
}
function add_post_meta( $id, $key, $value, $unique = false ) {
	return update_post_meta( $id, $key, $value );
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['W']['meta'][ (int) $id ][ $key ] );
	return true;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['W']['options'] ) ? $GLOBALS['W']['options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['W']['options'][ $name ] = $value;
	return true;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['W']['options'] ) ) {
		return false;
	}
	$GLOBALS['W']['options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['W']['options'][ $name ] );
	return true;
}
function get_transient( $name ) {
	return $GLOBALS['W']['transients'][ $name ] ?? false;
}
function set_transient( $name, $value, $expiry = 0 ) {
	$GLOBALS['W']['transients'][ $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( $GLOBALS['W']['transients'][ $name ] );
	return true;
}
function wp_cache_delete( $key, $group = '' ) { return true; }

/**
 * The one query the templates make of the database themselves: how many
 * products are set to a template.
 */
class Template_World_DB {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $options  = 'wp_options';
	public $prefix   = 'wp_';
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	/** The end of a bulk push asks which of its products did not finish: none here. */
	public function get_col( $query ) { return array(); }
	/** The mapper's own lookup: the product holding an eBay item number. */
	public function get_row( $query ) {
		foreach ( $GLOBALS['W']['meta'] as $id => $meta ) {
			if ( isset( $query[1][0] ) && (string) ( $meta['_ebay_item_id'] ?? '' ) === (string) $query[1][0] ) {
				return (object) array( 'post_id' => (string) $id, 'post_status' => 'publish' );
			}
		}
		return null;
	}
	public function get_var( $query ) {
		$n = 0;
		foreach ( $GLOBALS['W']['meta'] as $meta ) {
			if ( isset( $query[1][0] ) && ( $meta[ $query[1][0] ] ?? null ) === $query[1][1] ) {
				$n++;
			}
		}
		return $n;
	}
}
$GLOBALS['wpdb'] = new Template_World_DB();

// --- formatting and i18n -----------------------------------------------------

function __( $text, $domain = null ) { return $text; }
function _x( $text, $context, $domain = null ) { return $text; }
function _n( $single, $plural, $n, $domain = null ) { return 1 === (int) $n ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_xml( $text ) { return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return str_replace( array( '"', "'", '<', '>', ' ' ), array( '%22', '%27', '%3C', '%3E', '%20' ), (string) $url ); }
function esc_js( $text ) { return addslashes( (string) $text ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function sanitize_text_field( $text ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( (string) $title ) ), '-' ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $text ) ) ); }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $name ); }
function get_user_meta( $id, $key = '', $single = false ) { return $GLOBALS['W']['user_meta'][ (int) $id ][ $key ] ?? ''; }
function update_user_meta( $id, $key, $value ) { $GLOBALS['W']['user_meta'][ (int) $id ][ $key ] = $value; return true; }
function wp_get_referer() { return false; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function home_url( $path = '' ) { return $GLOBALS['W']['home'] . $path; }
function admin_url( $path = '' ) { return $GLOBALS['W']['home'] . '/wp-admin/' . $path; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function current_time( $type = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); }
function current_user_can( $cap, ...$args ) {
	if ( isset( $GLOBALS['W']['caps'][ $cap ] ) ) {
		return (bool) $GLOBALS['W']['caps'][ $cap ];
	}
	return (bool) $GLOBALS['W']['user_can'];
}
function get_current_user_id() { return 1; }
function wp_allowed_protocols() { return array( 'http', 'https', 'mailto' ); }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/tcg-template-uploads', 'baseurl' => $GLOBALS['W']['home'] . '/wp-content/uploads' ); }
function _deprecated_argument( ...$args ) {}
function _doing_it_wrong( ...$args ) {}
function term_exists( $term, $taxonomy = '' ) { return isset( $GLOBALS['W']['terms'][ (int) $term ] ) ? array( 'term_id' => (int) $term ) : null; }
function get_ancestors( $id, $object_type = '', $resource_type = '' ) {
	$out = array();
	$at  = (int) $id;
	while ( ! empty( $GLOBALS['W']['terms'][ $at ]['parent'] ) ) {
		$at    = (int) $GLOBALS['W']['terms'][ $at ]['parent'];
		$out[] = $at;
	}
	return $out;
}

// WordPress's own paragraph formatting is not needed: the content filters
// "core" calls are each optional, and the_content is a real filter here.
function wp_attachment_is_image( $id ) { return isset( $GLOBALS['W']['images'][ (int) $id ] ); }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return $GLOBALS['W']['images'][ (int) $id ] ?? false; }

// --- WooCommerce, played ------------------------------------------------------

class WC_Product_Attribute {
	public $name;
	public $options;
	public $taxonomy;
	public $variation;
	public function __construct( $name, array $options, $taxonomy = false, $variation = false ) {
		$this->name = $name; $this->options = $options; $this->taxonomy = $taxonomy; $this->variation = $variation;
	}
	public function get_name() { return $this->name; }
	public function get_options() { return $this->options; }
	public function is_taxonomy() { return $this->taxonomy; }
	public function get_variation() { return $this->variation; }
}

class WC_Product {
	public $id;
	public $data = array(
		'name'              => '',
		'description'       => '',
		'short_description' => '',
		'sku'               => '',
		'regular_price'     => '10.00',
		'stock'             => 1,
		'image_id'          => 0,
		'gallery'           => array(),
		'categories'        => array(),
		'attributes'        => array(),
		'status'            => 'publish',
		'type'              => 'simple',
	);
	public function __construct( $id, array $data = array() ) {
		$this->id   = (int) $id;
		$this->data = array_merge( $this->data, $data );
		$GLOBALS['W']['products'][ $this->id ] = $this;
	}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->data['name']; }
	public function get_description() { return $this->data['description']; }
	public function get_short_description() { return $this->data['short_description']; }
	public function get_sku() { return $this->data['sku']; }
	public function get_regular_price() { return $this->data['regular_price']; }
	public function get_stock_quantity() { return $this->data['stock']; }
	public function managing_stock() { return null !== $this->data['stock']; }
	public function get_image_id() { return $this->data['image_id']; }
	public function get_gallery_image_ids() { return $this->data['gallery']; }
	public function get_category_ids() { return $this->data['categories']; }
	public function get_attributes() { return $this->data['attributes']; }
	public function get_status() { return $this->data['status']; }
	public function get_parent_id() { return 0; }
	public function get_children() { return array(); }
	public function get_weight() { return ''; }
	public function is_type( $type ) { return $this->data['type'] === $type; }
	public function get_meta( $key, $single = true ) { return get_post_meta( $this->id, $key, true ); }
	public function update_meta_data( $key, $value ) { $GLOBALS['W']['meta'][ $this->id ][ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $GLOBALS['W']['meta'][ $this->id ][ $key ] ); }
	public function save() { return $this->id; }
	public function read_meta_data( $force = false ) {}
	public function is_in_stock() { return true; }
}
class WC_Product_Variable extends WC_Product {
	public $variation_attributes = array();
	public function get_variation_attributes() { return $this->variation_attributes; }
}
class WC_Product_Variation extends WC_Product {}

function wc_get_product( $id ) { return $GLOBALS['W']['products'][ (int) $id ] ?? false; }
function wc_attribute_label( $name, $product = null ) {
	if ( 0 === strpos( $name, 'pa_' ) ) {
		return ucwords( str_replace( '-', ' ', substr( $name, 3 ) ) );
	}
	return $name;
}
function wc_get_product_terms( $product_id, $taxonomy, $args = array() ) {
	$product = wc_get_product( $product_id );
	foreach ( $product->get_attributes() as $a ) {
		if ( $a->get_name() === $taxonomy ) {
			$names = array();
			foreach ( $a->get_options() as $term_id ) {
				$names[] = $GLOBALS['W']['term_names'][ $taxonomy . '|' . $term_id ] ?? (string) $term_id;
			}
			return $names;
		}
	}
	return array();
}
function get_term_by( $field, $value, $taxonomy ) {
	$name = $GLOBALS['W']['term_names'][ $taxonomy . '|' . $value ] ?? null;
	return null === $name ? false : (object) array( 'name' => $name );
}
function wc_format_decimal( $n, $dp = false ) { return false === $dp ? (string) $n : number_format( (float) $n, (int) $dp, '.', '' ); }

// --- the plugin's other classes, as far as a description goes -----------------

class TCGiant_Sync_Logger {
	public static function log( $message, $level = 'info' ) { $GLOBALS['W']['log'][] = array( $level, $message ); }
	public static function warning( $message ) { self::log( $message, 'warning' ); }
	public static function error( $message ) { self::log( $message, 'error' ); }
}
class TCGiant_Sync_OAuth {
	/** A shop set up to push: a category, a location, and its three policies. */
	public static $settings = array(
		'marketplace'               => 'EBAY_US',
		'export_category_id'        => '261328',
		'export_location'           => 'Chicago, IL',
		'export_postal_code'        => '60601',
		'export_fulfillment_policy' => 'FP1',
		'export_return_policy'      => 'RP1',
		'export_payment_policy'     => 'PP1',
	);
	/** Whether the shop is connected to eBay. */
	public static $connected = true;
	public static function instance() { return new self(); }
	public function get_settings() { return self::$settings; }
	public function is_authenticated() { return self::$connected; }
	public function get_authorization_url() { return 'https://auth.ebay.example/connect'; }
}

// A live shop, by wp-config, for TCGiant_Sync_Environment (3.28.0), which a
// push and a bulk push ask before anything goes to eBay. Staging copies are
// test-staging-detect.php's.
if ( ! defined( 'TCGIANT_SYNC_IS_STAGING' ) ) {
	define( 'TCGIANT_SYNC_IS_STAGING', false );
}
require_once $ROOT . '/includes/class-tcgiant-sync-environment.php';
require $ROOT . '/includes/class-tcgiant-sync-catalog.php';
require $ROOT . '/includes/class-tcgiant-sync-api.php';
require $ROOT . '/includes/class-tcgiant-sync-sites.php';
require $ROOT . '/includes/class-tcgiant-sync-exporter.php';
require $ROOT . '/includes/class-tcgiant-sync-template-renderer.php';
require $ROOT . '/includes/class-tcgiant-sync-template-sanitiser.php';
require $ROOT . '/includes/class-tcgiant-sync-template-policies.php';
require $ROOT . '/includes/class-tcgiant-sync-templates.php';
require $ROOT . '/includes/class-tcgiant-sync-template-starters.php';

/**
 * eBay, played: no category aspects, so names go out as they are, and every
 * Trading call recorded and answered from $answers (by call name) or with a
 * new item number.
 */
class Template_World_API extends TCGiant_Sync_API {
	public $aspects = array();
	public $calls   = array();
	public $answers = array();
	public function get_category_aspects( $category_id ) { return $this->aspects; }
	public function trading_api_request( $call_name, $xml_body, $allow_retry = true, $max_body_bytes = 0 ) {
		$this->calls[] = array( $call_name, $xml_body );
		return $this->answers[ $call_name ] ?? array( 'Ack' => 'Success', 'ItemID' => '110000000001' );
	}
	/** The Account API's policy lists, played: what "Fetch eBay Policies" reads. */
	public $policies = array( 'fulfillment' => array(), 'return' => array(), 'payment' => array() );
	public function get_fulfillment_policies( $marketplace_id = null ) {
		$this->calls[] = array( 'getFulfillmentPolicies', '' );
		return array( 'fulfillmentPolicies' => $this->policies['fulfillment'] );
	}
	public function get_return_policies( $marketplace_id = null ) {
		$this->calls[] = array( 'getReturnPolicies', '' );
		return array( 'returnPolicies' => $this->policies['return'] );
	}
	public function get_payment_policies( $marketplace_id = null ) {
		$this->calls[] = array( 'getPaymentPolicies', '' );
		return array( 'paymentPolicies' => $this->policies['payment'] );
	}
}
function wp_generate_uuid4() { return sprintf( '%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand( 0, 65535 ), mt_rand() ); }
$GLOBALS['API'] = new Template_World_API();
$tcg_slot = new ReflectionProperty( 'TCGiant_Sync_API', '_instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$tcg_slot->setAccessible( true );
}
$tcg_slot->setValue( null, $GLOBALS['API'] );

/** The exporter, with nothing registered. */
function exporter() {
	static $x = null;
	if ( null === $x ) {
		$x = ( new ReflectionClass( 'TCGiant_Sync_Exporter' ) )->newInstanceWithoutConstructor();
	}
	return $x;
}

/** A private method of a class, callable. */
function private_method( $class, $name ) {
	$m = new ReflectionMethod( $class, $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m;
}

/** The exporter's own XML for a product. */
function item_xml( WC_Product $product, array $settings ) {
	return exporter()->build_item_xml_public( $product, $settings );
}

/** A product's export settings as a push resolves them, on a configured shop. */
function push_settings( WC_Product $product, array $extra = array() ) {
	return array_merge( exporter()->get_export_settings( $product->get_id() ), $extra );
}

/** A template saved through the one save path. */
function make_template( array $data ) {
	return TCGiant_Sync_Templates::save( 0, array_merge( array( 'name' => 'T', 'status' => 'publish', 'html' => '', 'css' => '' ), $data ), 1 );
}

/** The description a push sends for a product. */
function described( WC_Product $product, array $extra = array() ) {
	return exporter()->build_listing_description( $product, push_settings( $product, $extra ) );
}

$pass = 0;
$fail = 0;

// A harness that stops before done() - a handler's own exit, say, or a fatal
// error part way - has not run its checks, so it must not pass.
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
