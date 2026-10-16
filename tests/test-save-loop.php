<?php
/**
 * Harness: another plugin's product save that goes round for ever, and the
 * breaker that stops it.
 *
 * Jon's WordPress.com shop, after 3.27.3 and an hourly sync: every delta run
 * still died in the first listing it saved. The record of the death said
 *
 *   Allowed memory size of 536870912 bytes exhausted ... WooCommerce saving
 *   the product ... 139 s into the request; memory peak 482 MB of 512M ...
 *   WordPress hooks running: woocommerce_update_product >
 *   woocommerce_update_product > woocommerce_update_product > ... ;
 *   1941215 object-cache reads during the item, the cache emptied 8 time(s).
 *
 * Something hooked to woocommerce_update_product saves the product again,
 * and WooCommerce fires that hook at the end of every update whether
 * anything changed or not - so the save fires the hook, the hook saves, for
 * thousands of rounds until PHP gives up. The plugin registers nothing on
 * that hook. Emptying the object cache could not help: the memory was the
 * recursion.
 *
 * Loads WordPress's own plugin.php and WP_Hook - WordPress 7.1.3, kept in
 * tests/wordpress, copied out under a made-up ABSPATH so they sit outside the
 * plugin as they do on a site; --wp=<dir> runs against another copy - and
 * the real TCGiant_Sync_Runtime. WooCommerce's save is played: it writes,
 * then fires woocommerce_update_product, as WC_Product_Data_Store_CPT::update()
 * does. The other plugins are real files in a made-up WP_PLUGIN_DIR, so the
 * breaker reads a real backtrace. Two cases run in a child PHP process with
 * 64 MB: the loop on its own dies, the loop with the breaker finishes.
 *
 * The breaker takes off only the callback that saved again, so every other
 * plugin on the hook - before it and after it, one that saves the product
 * once more included - still runs. Another callback that keeps the loop going
 * is taken off too, and at ten rounds, or when the backtrace cannot say which
 * callback saved again, everything after the breaker comes off, and plugins
 * after the culprit are skipped for that save. All of it is run here, and
 * what the log says about each.
 *
 * @package TCGiant_Sync
 */

$ROOT = dirname( __DIR__ );

$WP_SRC = __DIR__ . '/wordpress';
$CHILD  = '';
$SITE   = '';
foreach ( array_slice( (array) ( $argv ?? array() ), 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--wp=' ) ) {
		$WP_SRC = substr( $arg, 5 );
	} elseif ( 0 === strpos( $arg, '--site=' ) ) {
		$SITE = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--child=' ) ) {
		$CHILD = substr( $arg, 8 );
	}
}

// --- A site: WordPress in wp-includes, plugins and a theme in wp-content -----

if ( '' === $SITE ) {
	$SITE = sys_get_temp_dir() . '/tcgiant-save-loop-' . getmypid();
}

function harness_write( $path, $code ) {
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $code );
}

function harness_remove( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $dir );
}

if ( ! is_dir( $SITE ) ) {
	mkdir( $SITE, 0777, true );
}
$SITE = realpath( $SITE );

foreach ( glob( $WP_SRC . '/*.php' ) as $core ) {
	harness_write( $SITE . '/wp-includes/' . basename( $core ), file_get_contents( $core ) );
}

// WooCommerce's save, as far as hooks go: the write, then the hook.
harness_write( $SITE . '/wp-content/plugins/woocommerce/includes/data-stores/class-harness-product-data-store.php', <<<'PHP'
<?php
function harness_wc_save( $id ) {
	$GLOBALS['WRITES'][] = (int) $id;
	do_action( 'woocommerce_update_product', (int) $id, new Harness_Product( $id ) );
	$GLOBALS['FINISHED'][] = (int) $id;
	return (int) $id;
}
function harness_wc_update_stock( $id ) {
	harness_wc_save( $id );
	do_action( 'woocommerce_product_set_stock', new Harness_Product( $id ) );
}
// The same save, by the other hook that ends every update.
function harness_wc_save_props( $id ) {
	$GLOBALS['WRITES'][] = (int) $id;
	do_action( 'woocommerce_product_object_updated_props', new Harness_Product( $id ), array( 'regular_price' ) );
	$GLOBALS['FINISHED'][] = (int) $id;
}
PHP
);

// WordPress's wp_update_post(), as far as hooks go.
harness_write( $SITE . '/wp-includes/harness-post.php', <<<'PHP'
<?php
function harness_wp_update_post( $id ) {
	do_action( 'save_post', (int) $id, null, true );
}
PHP
);

// The plugin that goes round: it saves the product from the hook, to keep
// something it worked out from the save.
harness_write( $SITE . '/wp-content/plugins/loopy-seo/loopy-seo.php', <<<'PHP'
<?php
/**
 * Plugin Name: Loopy SEO
 */
class Loopy_SEO {
	public static $rounds = 0;
	public static function on_update( $id ) {
		self::$rounds++;
		self::remember( $id );
	}
	private static function remember( $id ) {
		harness_wc_save( $id );
	}
}
PHP
);

// One that saves once more, and knows not to again: a save inside a save.
harness_write( $SITE . '/wp-content/plugins/tidy-meta/tidy-meta.php', <<<'PHP'
<?php
/**
 * Plugin Name: Tidy Meta
 */
class Tidy_Meta {
	public static $runs    = 0;
	public static $deepest = 0;
	private static $saving = false;
	public static function on_update( $id ) {
		self::$runs++;
		self::$deepest = max( self::$deepest, count( array_keys( $GLOBALS['wp_current_filter'], 'woocommerce_update_product', true ) ) );
		if ( self::$saving ) {
			return;
		}
		self::$saving = true;
		harness_wc_save( $id );
		self::$saving = false;
	}
}
PHP
);

// One that goes round seven times and then stops by itself.
harness_write( $SITE . '/wp-content/plugins/seven-rounds/seven-rounds.php', <<<'PHP'
<?php
/**
 * Plugin Name: Seven Rounds
 */
class Seven_Rounds {
	public static $rounds = 0;
	public static function on_update( $id ) {
		if ( ++self::$rounds < 7 ) {
			harness_wc_save( $id );
		}
	}
}
PHP
);

// One that goes round through another hook: wp_update_post() from the
// product hook, and a well-behaved plugin saving the product from save_post.
harness_write( $SITE . '/wp-content/plugins/chain-sync/chain-sync.php', <<<'PHP'
<?php
/**
 * Plugin Name: Chain Sync
 */
class Chain_Sync {
	public static function on_update( $id ) {
		harness_wp_update_post( $id );
	}
}
PHP
);
harness_write( $SITE . '/wp-content/plugins/post-saver/post-saver.php', <<<'PHP'
<?php
/**
 * Plugin Name: Post Saver
 */
class Post_Saver {
	public static function on_save_post( $id ) {
		harness_wc_save( $id );
	}
	public static function touch( $id ) {
		harness_wc_save( $id );
	}
}
PHP
);

// One that goes round on the stock hook.
harness_write( $SITE . '/wp-content/plugins/stock-mirror/stock-mirror.php', <<<'PHP'
<?php
/**
 * Plugin Name: Stock Mirror
 */
class Stock_Mirror {
	public static $rounds = 0;
	public static function on_set_stock( $product ) {
		self::$rounds++;
		harness_wc_update_stock( $product->get_id() );
	}
}
PHP
);

// Linked products, each saving the next: a save inside a save, six deep, but
// never the same product twice. And two products that save each other for
// ever.
harness_write( $SITE . '/wp-content/plugins/linked-products/linked-products.php', <<<'PHP'
<?php
/**
 * Plugin Name: Linked Products
 */
class Linked_Products {
	public static function on_update( $id ) {
		if ( $id < 906 ) {
			harness_wc_save( $id + 1 );
		}
	}
}
class Ping_Pong {
	public static function on_update( $id ) {
		harness_wc_save( 951 === $id ? 952 : 951 );
	}
}
PHP
);

// Callbacks the backtrace names differently from how they were added: a
// closure, a method an object's class inherits, and an object's own method.
harness_write( $SITE . '/wp-content/plugins/odd-callbacks/odd-callbacks.php', <<<'PHP'
<?php
/**
 * Plugin Name: Odd Callbacks
 */
class Odd_Callbacks {
	public static $loops;
	public static $after;
	public static $after_runs = 0;
	public static function make() {
		self::$loops = function ( $id ) {
			harness_wc_save( $id );
		};
		self::$after = function ( $id ) {
			self::$after_runs++;
		};
	}
}
Odd_Callbacks::make();
class Odd_Base_Saver {
	public function on_update( $id ) {
		harness_wc_save( $id );
	}
}
class Odd_Shop_Saver extends Odd_Base_Saver {
}
PHP
);

// One whose callback is answered by __call(): the backtrace names __call, not
// the method it was added as, so nothing on the hook can be matched to it. It
// takes the product as well, and would die given one argument.
harness_write( $SITE . '/wp-content/plugins/magic-hooks/magic-hooks.php', <<<'PHP'
<?php
/**
 * Plugin Name: Magic Hooks
 */
class Magic_Hooks {
	public static $rounds  = 0;
	public static $looping = array();
	public static $seen    = array();
	public function __call( $name, $args ) {
		self::$rounds++;
		self::$seen[] = array( $args[0], isset( $args[1] ) && is_object( $args[1] ) );
		if ( in_array( $args[0], self::$looping, true ) ) {
			harness_wc_save( $args[1]->get_id() );
		}
	}
}
PHP
);

// WooCommerce's own code saving again.
harness_write( $SITE . '/wp-content/plugins/woocommerce/src/Internal/Resaver.php', <<<'PHP'
<?php
class WC_Internal_Resaver {
	public static function on_update( $id ) {
		harness_wc_save( $id );
	}
}
PHP
);

// One that saves again from the hook WooCommerce fires with the props a save
// changed.
harness_write( $SITE . '/wp-content/plugins/props-watch/props-watch.php', <<<'PHP'
<?php
/**
 * Plugin Name: Props Watch
 */
class Props_Watch {
	public static $rounds = 0;
	public static function on_props( $product, $props ) {
		self::$rounds++;
		harness_wc_save_props( $product->get_id() );
	}
}
PHP
);

// A theme that goes round, one in a folder of themes registered with
// register_theme_directory(), and code outside any plugin or theme that does.
harness_write( $SITE . '/wp-content/themes/shopfront/functions.php', <<<'PHP'
<?php
function shopfront_touch_product( $id ) {
	harness_wc_save( $id );
}
PHP
);
harness_write( $SITE . '/wp-content/extra-themes/kids-shop/functions.php', <<<'PHP'
<?php
function kids_shop_touch_product( $id ) {
	harness_wc_save( $id );
}
PHP
);
harness_write( $SITE . '/wp-includes/rogue.php', <<<'PHP'
<?php
function rogue_touch_product( $id ) {
	harness_wc_save( $id );
}
PHP
);

// WordPress.com keeps the plugins it manages outside the site, in
// /wordpress/plugins/<name>/<version>, and links each folder into
// wp-content/plugins. PHP names the real file; WordPress records the link in
// $wp_plugin_paths. Here the real files sit beside the site, outside it as
// they are on WordPress.com, and a copy of the main file in wp-content/plugins
// stands in for the link - what WordPress reads a plugin's header through.
$WPCOM = $SITE . '-wpcom';
$managed = <<<'PHP'
<?php
/**
 * Plugin Name: Managed SEO
 */
class Managed_SEO {
	public static function on_update( $id ) {
		harness_wc_save( $id );
	}
}
PHP;
harness_write( $WPCOM . '/wordpress/plugins/managed-seo/2.1/managed-seo.php', $managed );
harness_write( $SITE . '/wp-content/plugins/managed-seo/managed-seo.php', $managed );
// A folder linked in from somewhere not called plugins - as a Composer-built
// site does - which only the link can name.
harness_write( $WPCOM . '/vendor/acme/linked-feed/linked-feed.php', <<<'PHP'
<?php
/**
 * Plugin Name: Linked Feed
 */
class Linked_Feed {
	public function on_update( $id ) {
		harness_wc_save( $id );
	}
}
PHP
);
// And one WordPress.com loads from there itself, with no link at all.
harness_write( $WPCOM . '/wordpress/plugins/wpcom-tools/1.0/wpcom-tools.php', <<<'PHP'
<?php
class Wpcom_Tools {
	public static function on_update( $id ) {
		harness_wc_save( $id );
	}
}
PHP
);
$WPCOM = realpath( $WPCOM );

define( 'ABSPATH', $SITE . '/' );
define( 'WP_CONTENT_DIR', $SITE . '/wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );

require $SITE . '/wp-includes/plugin.php';
require $SITE . '/wp-includes/harness-post.php';
require $SITE . '/wp-includes/rogue.php';
require WP_PLUGIN_DIR . '/woocommerce/includes/data-stores/class-harness-product-data-store.php';
foreach ( array( 'loopy-seo', 'tidy-meta', 'seven-rounds', 'chain-sync', 'post-saver', 'stock-mirror', 'linked-products', 'odd-callbacks', 'props-watch', 'magic-hooks' ) as $plugin ) {
	require WP_PLUGIN_DIR . '/' . $plugin . '/' . $plugin . '.php';
}
require WP_PLUGIN_DIR . '/woocommerce/src/Internal/Resaver.php';
require WP_CONTENT_DIR . '/themes/shopfront/functions.php';
require WP_CONTENT_DIR . '/extra-themes/kids-shop/functions.php';
require $WPCOM . '/wordpress/plugins/managed-seo/2.1/managed-seo.php';
require $WPCOM . '/vendor/acme/linked-feed/linked-feed.php';
require $WPCOM . '/wordpress/plugins/wpcom-tools/1.0/wpcom-tools.php';

// --- WordPress, as far as the runtime asks -----------------------------------

$OPTIONS = array(
	'active_plugins' => array( 'woocommerce/woocommerce.php', 'loopy-seo/loopy-seo.php', 'tidy-meta/tidy-meta.php', 'stock-mirror/stock-mirror.php', 'managed-seo/managed-seo.php', 'linked-feed/linked-feed.php', 'magic-hooks/magic-hooks.php' ),
);
$META     = array();
$LOGGED   = array();
$WRITES   = array();
$FINISHED = array();
$THROW    = false;

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['OPTIONS'] ) ? $GLOBALS['OPTIONS'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['OPTIONS'][ $name ] = $value;
	return true;
}
function get_post_meta( $id, $key = '', $single = false ) {
	if ( $GLOBALS['THROW'] ) {
		throw new RuntimeException( 'MySQL server has gone away' );
	}
	return $GLOBALS['META'][ (int) $id ][ $key ] ?? '';
}
function wp_get_post_parent_id( $id ) {
	return (int) ( $GLOBALS['META'][ (int) $id ]['_parent'] ?? 0 );
}
// As WordPress reads a plugin's header.
function get_file_data( $file, $headers ) {
	$head = (string) file_get_contents( $file, false, null, 0, 8192 );
	$out  = array();
	foreach ( $headers as $field => $label ) {
		$out[ $field ] = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $head, $m ) ? trim( $m[1] ) : '';
	}
	return $out;
}
function wp_using_ext_object_cache() { return false; }

class Harness_Product {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
}

class TCGiant_Sync_Logger {
	public static function warning( $m ) {
		$GLOBALS['LOGGED'][] = $m;
		if ( '' !== $GLOBALS['CHILD'] ) {
			echo 'LOGGED: ' . $m . "\n";
		}
	}
	public static function write_now( $m, $level = 'error' ) {
		$GLOBALS['LOGGED'][] = $m;
	}
}
$wpdb = null;

require $ROOT . '/includes/class-tcgiant-sync-runtime.php';

/** Save a product the way the plugin's mapper does: from outside any hook. */
function save_product( $id ) {
	$GLOBALS['WRITES']   = array();
	$GLOBALS['FINISHED'] = array();
	return harness_wc_save( $id );
}

/** The priority a callback is on a hook at, or false: the same in every WordPress since 4.7. */
function hooked( $hook, $callback ) {
	return has_action( $hook, $callback );
}

// --- The child: one save of Jon's loop, in 64 MB -----------------------------

if ( '' !== $CHILD ) {
	// PHP's own report of a fatal is what says how it ended: after running
	// out of memory this deep, a shutdown function of the harness's cannot
	// even be called.
	ini_set( 'memory_limit', '64M' );
	$META[900] = array( '_ebay_item_id' => '297598275224' );
	add_action( 'woocommerce_update_product', array( 'Loopy_SEO', 'on_update' ), 10, 1 );
	if ( 'breaker' === $CHILD ) {
		TCGiant_Sync_Runtime::guard_loops();
	}
	save_product( 900 );
	TCGiant_Sync_Runtime::unguard_loops();
	echo 'COMPLETED: ' . Loopy_SEO::$rounds . ' rounds, ' . count( $GLOBALS['FINISHED'] ) . " saves finished\n";
	exit( 0 );
}

// --- The parent ---------------------------------------------------------------

// A breaker that fails sends this harness round for ever too: it should die
// soon, not take the machine's memory with it.
ini_set( 'memory_limit', '256M' );

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$LOOPY = array( 'Loopy_SEO', 'on_update' );
$MAGIC = array( new Magic_Hooks(), 'on_update' );
$OURS  = array( 'TCGiant_Sync_Runtime', 'on_save_hook' );
$HELD  = array( 'TCGiant_Sync_Runtime', 'hold_place' );
$WUP   = 'woocommerce_update_product';

echo "\nWORDPRESS'S OWN HOOKS, FROM " . str_replace( '\\', '/', $WP_SRC ) . "\n" . str_repeat( '=', 126 ) . "\n";
check( 'plugin.php and WP_Hook are WordPress\'s, outside the plugin', array( class_exists( 'WP_Hook', false ), 0 !== strpos( str_replace( '\\', '/', ( new ReflectionClass( 'WP_Hook' ) )->getFileName() ), str_replace( '\\', '/', $ROOT ) ) ), array( true, true ) );

echo "\nWITH THE BREAKER OFF, NOTHING IS DIFFERENT\n" . str_repeat( '=', 126 ) . "\n";

add_action( 'woocommerce_update_product', array( 'Seven_Rounds', 'on_update' ), 10, 1 );
save_product( 70 );
check( 'a plugin that goes round seven times and stops: all seven saves', array( Seven_Rounds::$rounds, count( $WRITES ), count( $FINISHED ) ), array( 7, 7, 7 ) );
check( '  nothing said, nothing taken off, nothing of the breaker\'s on the hook', array( $LOGGED, hooked( 'woocommerce_update_product', array( 'Seven_Rounds', 'on_update' ) ), hooked( 'woocommerce_update_product', $OURS ) ), array( array(), 10, false ) );
check( '  and called on its own it does nothing', TCGiant_Sync_Runtime::on_save_hook( 70, null ), 70 );

// Even if something left the breaker's callback on the hook.
Seven_Rounds::$rounds = 0;
add_action( 'woocommerce_update_product', $OURS, PHP_INT_MIN, 2 );
save_product( 71 );
remove_action( 'woocommerce_update_product', $OURS, PHP_INT_MIN );
check( '  nor does its callback left on the hook: all seven saves again, nothing taken off', array( Seven_Rounds::$rounds, count( $FINISHED ), $LOGGED, hooked( 'woocommerce_update_product', array( 'Seven_Rounds', 'on_update' ) ) ), array( 7, 7, array(), 10 ) );
remove_action( 'woocommerce_update_product', array( 'Seven_Rounds', 'on_update' ), 10 );

echo "\nJON'S LOOP: STOPPED AFTER FIVE ROUNDS, AND THE SAVE FINISHES\n" . str_repeat( '=', 126 ) . "\n";

$BYSTANDER = 0;
$LATE      = array();
function bystander( $id ) { $GLOBALS['BYSTANDER']++; }
// A plugin after it on the hook - a feed, a search index - that takes the
// product too.
function late_indexer( $id, $product ) { $GLOBALS['LATE'][] = array( $id, is_object( $product ) ); }
add_action( 'woocommerce_update_product', 'bystander', 5, 1 );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'late_indexer', 20, 2 );
$META[101] = array( '_ebay_item_id' => '297598275224' );
$META[102] = array( '_ebay_item_id' => '297598275225' );

TCGiant_Sync_Runtime::guard_loops();
check( 'on: the breaker is first on every save hook', array_map( function ( $hook ) { return hooked( $hook, $GLOBALS['OURS'] ); }, TCGiant_Sync_Runtime::LOOP_HOOKS ), array_fill( 0, count( TCGiant_Sync_Runtime::LOOP_HOOKS ), PHP_INT_MIN ) );

$returned = save_product( 101 );
check( 'the loop stops: five saves, the plugin saving again from four of them', array( count( $WRITES ), Loopy_SEO::$rounds ), array( TCGiant_Sync_Runtime::LOOP_DEPTH, TCGiant_Sync_Runtime::LOOP_DEPTH - 1 ) );
check( '  every one of the five saves finishes, the outer one last', array( count( $FINISHED ), end( $FINISHED ), $returned ), array( 5, 101, 101 ) );
check( '  WordPress is running no hook afterwards', $wp_current_filter, array() );
check( '  nor thinks it is', array( doing_action( 'woocommerce_update_product' ), doing_action() ), array( false, false ) );
check( '  only the plugin\'s callback is off the hook, a stand-in at its priority; the breaker and the others still on it', array( hooked( $WUP, $LOOPY ), hooked( $WUP, $HELD ), hooked( $WUP, $OURS ), hooked( $WUP, 'bystander' ), hooked( $WUP, 'late_indexer' ) ), array( false, 10, PHP_INT_MIN, 5, 20 ) );
check( '  every other plugin on the hook ran in every round: the one ahead of it, and the one after it', array( $BYSTANDER, count( $LATE ), $LATE[0] ?? null ), array( 5, 5, array( 101, true ) ) );

$line = $LOGGED[0] ?? '';
check( 'one warning', count( $LOGGED ), 1 );
check( '  in words a shop owner can act on, naming the plugin and what was skipped', $line, 'Saving WC #101 (eBay item 297598275224) went into a loop: every save fired woocommerce_update_product, and the plugin "Loopy SEO" (folder loopy-seo) saved the product again from it (Loopy_SEO::on_update). TCGiant stopped it after 5 rounds by skipping Loopy_SEO::on_update for the rest of that save; the product was saved, and everything else on woocommerce_update_product still ran. This loop is in the plugin "Loopy SEO" (folder loopy-seo), not in TCGiant, and it can slow every product save on this site - update or deactivate it, or ask its author.' );

echo "\nTHE NEXT PRODUCT: THE PLUGIN RUNS AGAIN, AND THE LOOP IS STOPPED AGAIN\n" . str_repeat( '=', 126 ) . "\n";

$BYSTANDER        = 0;
$LATE             = array();
Loopy_SEO::$rounds = 0;
save_product( 102 );
check( 'put back at the start of the next save, and run in it', array( Loopy_SEO::$rounds, $BYSTANDER, count( $LATE ) ), array( 4, 5, 5 ) );
check( '  stopped after five rounds again, every save finishing', array( count( $WRITES ), count( $FINISHED ), $wp_current_filter ), array( 5, 5, array() ) );
check( '  not said a second time in the request', count( $LOGGED ), 1 );

// Something adding a callback to the hook while the loop unwinds - as a
// plugin's own remove_action() and add_action() round a save does - and
// saving once more from there: a save inside a save, not a loop, so it is
// left on, and so is the newcomer. The culprit stays off for that save.
$NUDGED = false;
function late_comer( $id ) {}
function nudger( $id ) {
	if ( ! $GLOBALS['NUDGED'] && 4 === count( array_keys( $GLOBALS['wp_current_filter'], 'woocommerce_update_product', true ) ) ) {
		$GLOBALS['NUDGED'] = true;
		add_action( 'woocommerce_update_product', 'late_comer', 20, 1 );
		harness_wc_save( $id );
	}
}
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'nudger', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 103 );
check( 'a callback added as the loop unwinds, and one more save from there: both left on, the culprit off', array( $NUDGED, hooked( $WUP, 'nudger' ), hooked( $WUP, $LOOPY ), hooked( $WUP, 'late_comer' ), count( $FINISHED ), $wp_current_filter ), array( true, 10, false, 20, 6, array() ) );
TCGiant_Sync_Runtime::unguard_loops();
check( '  and the culprit put back in the order they were added', array( hooked( $WUP, 'late_comer' ), hooked( $WUP, 'nudger' ), hooked( $WUP, $LOOPY ), array_keys( $wp_filter[ $WUP ]->callbacks[10] ), hooked( $WUP, $HELD ) ), array( 20, 10, 10, array( 'Loopy_SEO::on_update', 'nudger' ), false ) );
check( '  still said only once', count( $LOGGED ), 1 );
remove_action( 'woocommerce_update_product', 'nudger', 10 );
remove_action( 'woocommerce_update_product', 'late_comer', 20 );
TCGiant_Sync_Runtime::guard_loops();

echo "\nOFF AGAIN: EVERYTHING PUT BACK\n" . str_repeat( '=', 126 ) . "\n";

save_product( 105 );
check( 'cut short by the last save', hooked( 'woocommerce_update_product', $LOOPY ), false );
TCGiant_Sync_Runtime::guard_loops();
TCGiant_Sync_Runtime::unguard_loops();
check( 'turned on inside another job and off again: still on for the outer one', array( hooked( 'woocommerce_update_product', $OURS ), hooked( 'woocommerce_update_product', $LOOPY ) ), array( PHP_INT_MIN, false ) );
TCGiant_Sync_Runtime::unguard_loops();
check( 'off: the plugin\'s callbacks are back where they were, the stand-in gone', array( hooked( $WUP, $LOOPY ), hooked( $WUP, 'bystander' ), hooked( $WUP, 'late_indexer' ), hooked( $WUP, $HELD ) ), array( 10, 5, 20, false ) );
check( '  and the breaker is off every hook', array_filter( array_map( function ( $hook ) { return false !== hooked( $hook, $GLOBALS['OURS'] ); }, TCGiant_Sync_Runtime::LOOP_HOOKS ) ), array() );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'bystander', 5 );
remove_action( 'woocommerce_update_product', 'late_indexer', 20 );

// Put back in its place among the callbacks at its priority, not at the end.
function first_at_ten( $id ) {}
function last_at_ten( $id ) { $GLOBALS['LAST_AT_TEN']++; }
$LAST_AT_TEN = 0;
add_action( 'woocommerce_update_product', 'first_at_ten', 10, 1 );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'last_at_ten', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 106 );
TCGiant_Sync_Runtime::unguard_loops();
check( 'one at the same priority after it ran in every round, and it went back between the two', array( $LAST_AT_TEN, array_keys( $wp_filter[ $WUP ]->callbacks[10] ) ), array( 5, array( 'first_at_ten', 'Loopy_SEO::on_update', 'last_at_ten' ) ) );
remove_action( 'woocommerce_update_product', 'first_at_ten', 10 );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'last_at_ten', 10 );

// A plugin ahead of it that puts it back on the hook whenever it is off, so
// that each cut is undone before the culprit's turn comes. Saving again from
// two rounds running, it is taken off again each round, until at ten rounds
// everything after the breaker comes off, the plugin putting it back too, and
// the loop ends.
function re_adder( $id ) {
	if ( false === has_action( 'woocommerce_update_product', $GLOBALS['LOOPY'] ) ) {
		add_action( 'woocommerce_update_product', $GLOBALS['LOOPY'], 10, 1 );
	}
}
$LOGGED = array();
add_action( 'woocommerce_update_product', 're_adder', 5, 1 );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 107 );
$readded = array( count( $WRITES ), count( $FINISHED ), $wp_current_filter, hooked( $WUP, 're_adder' ), hooked( $WUP, $LOOPY ) );
TCGiant_Sync_Runtime::unguard_loops();
check( 'a plugin that puts the culprit back every time: off again each round, everything off at ten, and it ends', $readded, array( 10, 10, array(), false, false ) );
check( '  both put back when the guard comes off', array( hooked( $WUP, 're_adder' ), hooked( $WUP, $LOOPY ) ), array( 5, 10 ) );
check( '  and the log takes back "everything else still ran"', $LOGGED, array( 'Saving WC #107 kept looping on woocommerce_update_product after TCGiant skipped Loopy_SEO::on_update. TCGiant stopped it after 10 rounds; the product was saved, but what other plugins had still to do on woocommerce_update_product in that save was skipped.' ) );
remove_action( 'woocommerce_update_product', 're_adder', 5 );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );

echo "\nWHEN THE BACKTRACE CANNOT SAY WHICH CALLBACK: EVERYTHING AFTER THE BREAKER COMES OFF\n" . str_repeat( '=', 126 ) . "\n";

// A plugin whose callback is answered by __call(): the backtrace names
// Magic_Hooks::__call, which nothing on the hook was added as, so which
// callback saved again cannot be told. The loop must stop all the same.
$LOGGED               = array();
$BYSTANDER            = 0;
$LATE                 = array();
Magic_Hooks::$looping = array( 111 );
add_action( 'woocommerce_update_product', 'bystander', 5, 1 );
add_action( 'woocommerce_update_product', $MAGIC, 10, 2 );
add_action( 'woocommerce_update_product', 'late_indexer', 20, 2 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 111 );
check( 'stopped all the same: five saves, every one finishing', array( count( $WRITES ), count( $FINISHED ), $wp_current_filter ), array( 5, 5, array() ) );
check( '  but a plugin after the one that loops never ran; one ahead of it ran in each round before the cut', array( count( $LATE ), $BYSTANDER ), array( 0, 4 ) );
check( '  everything but the breaker off the hook, and no stand-in', array( hooked( $WUP, 'bystander' ), hooked( $WUP, $MAGIC ), hooked( $WUP, 'late_indexer' ), hooked( $WUP, $HELD ), hooked( $WUP, $OURS ) ), array( false, false, false, false, PHP_INT_MIN ) );
check( '  and the warning says what was skipped', $LOGGED[0] ?? '', 'Saving WC #111 went into a loop: every save fired woocommerce_update_product, and the plugin "Magic Hooks" (folder magic-hooks) saved the product again from it (Magic_Hooks::__call). TCGiant stopped it after 5 rounds; the product was saved, but what other plugins do on woocommerce_update_product after Magic_Hooks::__call was skipped for this save. This loop is in the plugin "Magic Hooks" (folder magic-hooks), not in TCGiant, and it can slow every product save on this site - update or deactivate it, or ask its author.' );

// The next product, which it saves only once: all of it back, each with the
// arguments it was added with - the plugin's callback takes two, and would
// die given one - and run.
$LATE              = array();
Magic_Hooks::$seen = array();
save_product( 112 );
check( 'the next save: everything back, each with as many arguments as it was added with, and run', array( count( $LATE ), $LATE[0] ?? null, Magic_Hooks::$seen, $BYSTANDER, count( $WRITES ), count( $FINISHED ) ), array( 1, array( 112, true ), array( array( 112, true ) ), 5, 1, 1 ) );
TCGiant_Sync_Runtime::unguard_loops();
check( '  and again when the guard comes off', array( $wp_filter[ $WUP ]->callbacks[20]['late_indexer']['accepted_args'] ?? null, $wp_filter[ $WUP ]->callbacks[10][ _wp_filter_build_unique_id( $WUP, $MAGIC, 10 ) ]['accepted_args'] ?? null ), array( 2, 2 ) );
remove_action( 'woocommerce_update_product', 'bystander', 5 );
remove_action( 'woocommerce_update_product', $MAGIC, 10 );
remove_action( 'woocommerce_update_product', 'late_indexer', 20 );

echo "\nA NAME LEFT ON WORDPRESS'S LIST BY A HOOK THAT THREW\n" . str_repeat( '=', 126 ) . "\n";

// WordPress takes a hook's name off its list of the hooks running only when
// the hook returns. A queue runner that catches a failed action - as Action
// Scheduler does, then runs the next action in the batch - leaves the name on
// the list, and the job after it runs with one name too many. The runs of a
// hook are told apart by its WP_Hook object in the backtrace, not by that
// list, so the callback that saved again is still told.
function harness_failing_job() {
	throw new RuntimeException( 'remote API down' );
}
function harness_job() {
	TCGiant_Sync_Runtime::guard_loops();
	try {
		save_product( 141 );
	} finally {
		TCGiant_Sync_Runtime::unguard_loops();
	}
}
function harness_queue() {
	foreach ( array( 'harness_failing_job', 'harness_job' ) as $job ) {
		try {
			do_action( $job );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
add_action( 'harness_failing_job', 'harness_failing_job' );
add_action( 'harness_job', 'harness_job' );
add_action( 'harness_run_queue', 'harness_queue' );
$LATE              = array();
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'late_indexer', 20, 2 );
do_action( 'harness_run_queue' );
$stale             = $wp_current_filter;
$wp_current_filter = array();
$stale_hooked      = array( hooked( $WUP, $LOOPY ), hooked( $WUP, $HELD ), hooked( $WUP, 'late_indexer' ) );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'late_indexer', 20 );
check( 'an action that threw before the job: a name left on the list', $stale, array( 'harness_run_queue' ) );
check( '  the loop still stopped by skipping only its culprit, the plugin after it run in every round', array( Loopy_SEO::$rounds, count( $WRITES ), count( $FINISHED ), count( $LATE ) ), array( 4, 5, 5, 5 ) );
check( '  and everything back when the job is done', $stale_hooked, array( 10, false, 20 ) );

// The name left can be the cut hook's own. A product whose save threw from
// inside woocommerce_update_product - a feed's API down for a moment - is an
// item the delta gives up on, caught as it catches one, and it goes on to the
// next: by WordPress's list the hook is running for the rest of the run. Each
// product after it that goes round must still have the culprit put back at
// the start of its save, and the plugins taken off with it.
$FEED_DOWN = array();
function feed_ping( $id ) {
	if ( in_array( $id, $GLOBALS['FEED_DOWN'], true ) ) {
		throw new RuntimeException( 'feed API down' );
	}
}
// Per product: saves, rounds the plugin that loops saved again from, runs of
// the plugin ahead of it and of the one after it.
function harness_delta( array $ids ) {
	$per = array();
	TCGiant_Sync_Runtime::guard_loops();
	try {
		foreach ( $ids as $id ) {
			$GLOBALS['BYSTANDER'] = 0;
			$GLOBALS['LATE']      = array();
			Loopy_SEO::$rounds    = 0;
			Magic_Hooks::$rounds  = 0;
			try {
				save_product( $id );
			} catch ( Exception $e ) {
				unset( $e );
			} catch ( Error $e ) {
				unset( $e );
			}
			$per[ $id ] = array( count( $GLOBALS['WRITES'] ), Loopy_SEO::$rounds + Magic_Hooks::$rounds, $GLOBALS['BYSTANDER'], count( $GLOBALS['LATE'] ) );
		}
		$per['left'] = $GLOBALS['wp_current_filter'];
	} finally {
		TCGiant_Sync_Runtime::unguard_loops();
	}
	return $per;
}
$LOGGED    = array();
$FEED_DOWN = array( 151 );
add_action( 'woocommerce_update_product', 'feed_ping', 1, 1 );
add_action( 'woocommerce_update_product', 'bystander', 5, 1 );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'late_indexer', 20, 2 );
$delta             = harness_delta( array( 151, 152, 153 ) );
$wp_current_filter = array();
check( 'a save that threw from the hook itself, then two that go round: the hook\'s name left on the list', $delta['left'], array( 'woocommerce_update_product' ) );
check( '  the first stopped by skipping only its culprit', $delta[152], array( 5, 4, 5, 5 ) );
check( '  the culprit put back for the next, and run in it; stopped again', $delta[153], array( 5, 4, 5, 5 ) );
check( '  and everything back when the run is done', array( hooked( $WUP, $LOOPY ), hooked( $WUP, $HELD ), hooked( $WUP, 'late_indexer' ) ), array( 10, false, 20 ) );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );

// The same where the callback cannot be told, and everything after the
// breaker came off: the plugins ahead of it and after it go back too - for a
// product that goes round, and for one that does not.
Magic_Hooks::$looping = array( 162, 163 );
$FEED_DOWN            = array( 161 );
add_action( 'woocommerce_update_product', $MAGIC, 10, 2 );
$delta             = harness_delta( array( 161, 162, 163, 164 ) );
$wp_current_filter = array();
check( '  and where the callback cannot be told: stopped, the plugin ahead of it run in each round before the cut', array( $delta['left'], $delta[162] ), array( array( 'woocommerce_update_product' ), array( 5, 4, 4, 0 ) ) );
check( '  everything put back for the next that goes round, and run in it', $delta[163], array( 5, 4, 4, 0 ) );
check( '  and for one that does not: every plugin on the hook run', $delta[164], array( 1, 1, 1, 1 ) );
check( '  and nothing more said: both plugins were named earlier in the request', $LOGGED, array() );
remove_action( 'woocommerce_update_product', 'feed_ping', 1 );
remove_action( 'woocommerce_update_product', 'bystander', 5 );
remove_action( 'woocommerce_update_product', $MAGIC, 10 );

// Names left by saves that threw before the guard was on - other actions in
// the same queue batch - are not rounds of the next product's save, nor of
// any other: the breaker never saw them run. Four of them, then one save
// that does not go round: nothing cut, nothing said.
$LATE      = array();
$FEED_DOWN = array( 171 );
add_action( 'woocommerce_update_product', 'feed_ping', 1, 1 );
for ( $i = 0; $i < 4; $i++ ) {
	try {
		save_product( 171 );
	} catch ( Exception $e ) {
		unset( $e );
	}
}
$FEED_DOWN = array();
$LOGGED    = array();
$before    = count( $wp_current_filter );
TCGiant_Sync_Runtime::guard_loops();
save_product( 171 );
$four = array( $before, count( $WRITES ), count( $LATE ), hooked( $WUP, 'late_indexer' ), $LOGGED );
TCGiant_Sync_Runtime::unguard_loops();
$wp_current_filter = array();
check( 'four names left before the guard was on, then a save that does not go round: nothing cut, nothing said', $four, array( 4, 1, 1, 20, array() ) );
remove_action( 'woocommerce_update_product', 'feed_ping', 1 );
remove_action( 'woocommerce_update_product', 'late_indexer', 20 );

echo "\nA CULPRIT AT THE BREAKER'S OWN PRIORITY, ADDED AFTER IT\n" . str_repeat( '=', 126 ) . "\n";

// WordPress runs each priority from a copy of its callbacks, so one added at
// PHP_INT_MIN after the guard was turned on - after the breaker - still runs
// once in the round that was cut, and saves once more. Then no more.
$LATE              = array();
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', 'late_indexer', 20, 2 );
TCGiant_Sync_Runtime::guard_loops();
add_action( 'woocommerce_update_product', $LOOPY, PHP_INT_MIN, 1 );
save_product( 121 );
check( 'one more round after the cut, and no more, every save finishing', array( Loopy_SEO::$rounds, count( $WRITES ), count( $FINISHED ), $wp_current_filter ), array( 5, 6, 6, array() ) );
check( '  everything else on the hook still ran in every round', count( $LATE ), 6 );
check( '  only the culprit off', array( hooked( $WUP, $LOOPY ), hooked( $WUP, $OURS ), hooked( $WUP, 'late_indexer' ) ), array( false, PHP_INT_MIN, 20 ) );
TCGiant_Sync_Runtime::unguard_loops();
check( '  and put back at that priority when the guard comes off', hooked( $WUP, $LOOPY ), PHP_INT_MIN );
remove_action( 'woocommerce_update_product', $LOOPY, PHP_INT_MIN );

// The same where the callback cannot be told: what shares the breaker's
// priority is taken off it one by one.
Magic_Hooks::$rounds  = 0;
Magic_Hooks::$looping = array( 122 );
TCGiant_Sync_Runtime::guard_loops();
add_action( 'woocommerce_update_product', $MAGIC, PHP_INT_MIN, 2 );
save_product( 122 );
check( '  and where the callback cannot be told', array( Magic_Hooks::$rounds, count( $WRITES ), count( $FINISHED ), hooked( $WUP, $MAGIC ), hooked( $WUP, $OURS ), hooked( $WUP, 'late_indexer' ) ), array( 5, 6, 6, false, PHP_INT_MIN, false ) );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $MAGIC, PHP_INT_MIN );
remove_action( 'woocommerce_update_product', 'late_indexer', 20 );

echo "\nA LOOP STILL UNWINDING, HOWEVER DEEP THE WORK IN BETWEEN\n" . str_repeat( '=', 126 ) . "\n";

// Once a hook is cut, whether the loop is still unwinding is told from the
// backtrace, and its rounds are counted from the whole of WordPress's list -
// not just the top LOOP_WINDOW names. A plugin after the culprit, in the
// outermost round, does work three hundred hooks deep that saves the product:
// the culprit stays off for that save.
$DEEP = false;
function deep_worker( $id ) {
	global $wp_current_filter;
	if ( $GLOBALS['DEEP'] || 1 !== count( array_keys( $wp_current_filter, 'woocommerce_update_product', true ) ) ) {
		return;
	}
	$GLOBALS['DEEP']   = true;
	$was               = $wp_current_filter;
	$wp_current_filter = array_merge( $wp_current_filter, array_fill( 0, 300, 'harness_deep_work' ) );
	harness_wc_save( $id );
	$wp_current_filter = $was;
}
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'deep_worker', 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 131 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'deep_worker', 20 );
check( 'a save three hundred hooks above the outermost round: the culprit still off for it', array( $DEEP, Loopy_SEO::$rounds, count( $WRITES ), count( $FINISHED ), $wp_current_filter ), array( true, 4, 6, 6, array() ) );

// And a save of the same product from work that deep in the innermost round
// is counted with the five rounds below it, as it would be with nothing in
// between: a sixth round, with the culprit still off. The plugin that made it
// saves only the once - a save inside a save - so it is left on, and runs in
// every round.
$DEEP_RUNS = 0;
function deep_saver( $id ) {
	global $wp_current_filter;
	if ( 1 < ++$GLOBALS['DEEP_RUNS'] || 5 !== count( array_keys( $wp_current_filter, 'woocommerce_update_product', true ) ) ) {
		return;
	}
	$was               = $wp_current_filter;
	$wp_current_filter = array_merge( $wp_current_filter, array_fill( 0, 300, 'harness_deep_work' ) );
	harness_wc_save( $id );
	$wp_current_filter = $was;
}
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'deep_saver', 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 132 );
$deep = array( $DEEP_RUNS, hooked( $WUP, 'deep_saver' ), count( $WRITES ), count( $FINISHED ), $wp_current_filter );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'deep_saver', 20 );
check( '  a sixth save of the product from deep inside the fifth: the culprit off in it, the plugin that made it left on', $deep, array( 6, 20, 6, 6, array() ) );

// A second plugin after the culprit that saves the product again from work
// that deep every time it runs: its rounds are three hundred names apart on
// the list, and still counted. It saves from the fifth round and the sixth,
// and is taken off at the seventh.
$DEEP_LOOPS = 0;
function deep_looper( $id ) {
	global $wp_current_filter;
	if ( ++$GLOBALS['DEEP_LOOPS'] > 40 ) {
		return;
	}
	$was               = $wp_current_filter;
	$wp_current_filter = array_merge( $wp_current_filter, array_fill( 0, 300, 'harness_deep_work' ) );
	harness_wc_save( $id );
	$wp_current_filter = $was;
}
$LOGGED            = array();
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'deep_looper', 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 133 );
$deep = array( Loopy_SEO::$rounds, $DEEP_LOOPS, count( $WRITES ), count( $FINISHED ), $wp_current_filter, hooked( $WUP, $LOOPY ), hooked( $WUP, 'deep_looper' ), count( $LOGGED ) );
TCGiant_Sync_Runtime::unguard_loops();
check( '  a second that goes round through work that deep: taken off at the seventh round', $deep, array( 4, 2, 7, 7, array(), false, false, 1 ) );
check( '  and put back when the guard comes off', array( hooked( $WUP, $LOOPY ), hooked( $WUP, 'deep_looper' ) ), array( 10, 20 ) );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'deep_looper', 20 );

echo "\nA PLUGIN THAT SAVES ONCE MORE IS LEFT ALONE\n" . str_repeat( '=', 126 ) . "\n";

$LOGGED = array();
add_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 201 );
save_product( 202 );
TCGiant_Sync_Runtime::unguard_loops();
check( 'two saves of each product, its own inside the first, nothing cut', array( Tidy_Meta::$runs, Tidy_Meta::$deepest, count( $WRITES ), count( $FINISHED ) ), array( 4, 2, 2, 2 ) );
check( '  nothing said, its callback where it was', array( $LOGGED, hooked( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ) ) ), array( array(), 10 ) );
remove_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 10 );

// Linked products saving each other in turn: the hook six deep, but never
// for the same product twice - no loop, and the last of them is written.
$LOGGED = array();
add_action( 'woocommerce_update_product', array( 'Linked_Products', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 901 );
$linked = hooked( $WUP, array( 'Linked_Products', 'on_update' ) );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'Linked_Products', 'on_update' ), 10 );
check( 'six linked products, each saving the next: every one written, nothing cut, nothing said', array( $WRITES, count( $FINISHED ), $LOGGED, $linked ), array( array( 901, 902, 903, 904, 905, 906 ), 6, array(), 10 ) );

// But two products saving each other for ever are still a loop, each
// product's rounds counted on their own.
add_action( 'woocommerce_update_product', array( 'Ping_Pong', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 951 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'Ping_Pong', 'on_update' ), 10 );
check( '  two saving each other for ever: stopped at the first one\'s fifth round', array( $WRITES, count( $FINISHED ), $wp_current_filter, 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #951 went into a loop' ) ), array( array( 951, 952, 951, 952, 951, 952, 951, 952, 951 ), 9, array(), true ) );

// Only the top of WordPress's list is read for a loop, so a save deep inside
// other work is judged by its own rounds.
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
$wp_current_filter = array_fill( 0, 10000, 'action_scheduler_run_queue' );
save_product( 210 );
$left              = count( $wp_current_filter );
$wp_current_filter = array();
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
check( 'a loop ten thousand hooks deep in other work: still stopped after five rounds', array( Loopy_SEO::$rounds, count( $FINISHED ), $left ), array( 4, 5, 10000 ) );

// A plugin that saves once more, beside one that goes round: saving once more
// from each round of the loop, it saves a sixth time from the innermost. That
// is no loop of its own, so it is left alone, and every plugin on the hook but
// the culprit runs in every save. The way WordPress advises for save_post
// first: by taking itself off the hook round its own save, and putting itself
// back.
$ONCE  = 0;
$INDEX = array();
function once_saver( $id ) {
	$GLOBALS['ONCE']++;
	remove_action( 'woocommerce_update_product', 'once_saver', 20 );
	harness_wc_save( $id );
	add_action( 'woocommerce_update_product', 'once_saver', 20, 1 );
}
function feed_indexer( $id ) { $GLOBALS['INDEX'][] = $id; }
$LOGGED            = array();
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', 'once_saver', 20, 1 );
add_action( 'woocommerce_update_product', 'feed_indexer', 30, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 221 );
$once = array( Loopy_SEO::$rounds, $ONCE, count( $WRITES ), count( $FINISHED ), count( $INDEX ), $wp_current_filter, hooked( $WUP, $LOOPY ), hooked( $WUP, 'once_saver' ), hooked( $WUP, 'feed_indexer' ) );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', 'once_saver', 20 );
remove_action( 'woocommerce_update_product', 'feed_indexer', 30 );
check( 'beside one that goes round, one that takes itself off round its save: left alone, the indexer after it run in every save', $once, array( 4, 5, 10, 10, 10, array(), false, 20, 30 ) );

// With a flag of its own, at the culprit's priority: the rounds outside the
// innermost run it from their copy of that priority, after the culprit was
// taken off, and it saves once more from each of them too.
Tidy_Meta::$runs    = 0;
Tidy_Meta::$deepest = 0;
Loopy_SEO::$rounds  = 0;
$INDEX              = array();
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 10, 1 );
add_action( 'woocommerce_update_product', 'feed_indexer', 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 231 );
$once = array( Loopy_SEO::$rounds, Tidy_Meta::$runs, Tidy_Meta::$deepest, count( $WRITES ), count( $FINISHED ), count( $INDEX ), hooked( $WUP, $LOOPY ), hooked( $WUP, array( 'Tidy_Meta', 'on_update' ) ) );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 10 );
remove_action( 'woocommerce_update_product', 'feed_indexer', 20 );
check( '  one with a flag at the culprit\'s priority: left on, run in every save, the indexer too', $once, array( 4, 10, 6, 10, 10, 10, false, 10 ) );

// And after the culprit.
Tidy_Meta::$runs    = 0;
Tidy_Meta::$deepest = 0;
Loopy_SEO::$rounds  = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 241 );
$once = array( Loopy_SEO::$rounds, Tidy_Meta::$runs, Tidy_Meta::$deepest, count( $WRITES ), count( $FINISHED ), hooked( $WUP, $LOOPY ), hooked( $WUP, array( 'Tidy_Meta', 'on_update' ) ) );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 20 );
check( '  and after it: left on, run in every save', $once, array( 4, 10, 6, 10, 10, false, 20 ) );
check( '  and nothing said beyond the culprit, which was said already', $LOGGED, array() );

echo "\nANOTHER CALLBACK THAT KEEPS IT GOING\n" . str_repeat( '=', 126 ) . "\n";

// A second plugin that saves again every time too. It is left for the round
// it first saves from, which could be a save inside a save, and taken off at
// the next, for the rest of that save. A plugin after both runs in every
// save, and the log says what more was skipped.
$TOUCH             = array( 'Post_Saver', 'touch' );
$LOGGED            = array();
$LATE              = array();
Loopy_SEO::$rounds = 0;
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
add_action( 'woocommerce_update_product', $TOUCH, 20, 1 );
add_action( 'woocommerce_update_product', 'late_indexer', 30, 2 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 251 );
$second = array( Loopy_SEO::$rounds, count( $WRITES ), count( $FINISHED ), $wp_current_filter, hooked( $WUP, $LOOPY ), hooked( $WUP, $TOUCH ), count( $LATE ) );
TCGiant_Sync_Runtime::unguard_loops();
check( 'a second plugin that goes round: one more save, then it is off too, and the plugin after both runs in every save', $second, array( 4, 7, 7, array(), false, false, 7 ) );
check( '  both put back when the guard comes off', array( hooked( $WUP, $LOOPY ), hooked( $WUP, $TOUCH ), hooked( $WUP, 'late_indexer' ), hooked( $WUP, $HELD ) ), array( 10, 20, 30, false ) );
check( '  and the log says it was skipped as well', $LOGGED, array( 'Saving WC #251 kept looping on woocommerce_update_product after TCGiant skipped Loopy_SEO::on_update: the plugin in the folder post-saver saved the product again from it as well (Post_Saver::touch). TCGiant skipped Post_Saver::touch too, for the rest of that save.' ) );
remove_action( 'woocommerce_update_product', $TOUCH, 20 );

// One that cannot be matched to the hook, which saves again every time: no
// telling it from one that took itself off round its own save, so it is left
// - until the hook is running ten times inside itself for the product. Then
// everything after the breaker comes off, and the loop ends; the log says
// what that cost, once. A plugin after it at its priority still runs in the
// rounds that had it in hand, saving once more from each, and is no culprit.
$LOGGED               = array();
$LATE                 = array();
Loopy_SEO::$rounds    = 0;
Magic_Hooks::$rounds  = 0;
Magic_Hooks::$looping = array( 261, 262 );
Tidy_Meta::$runs      = 0;
add_action( 'woocommerce_update_product', $MAGIC, 20, 2 );
add_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 261 );
$tenth = array( Loopy_SEO::$rounds, Magic_Hooks::$rounds, Tidy_Meta::$runs, count( $WRITES ), count( $FINISHED ), $wp_current_filter, count( $LATE ), hooked( $WUP, $LOOPY ), hooked( $WUP, $MAGIC ), hooked( $WUP, 'late_indexer' ), hooked( $WUP, $OURS ) );
TCGiant_Sync_Runtime::unguard_loops();
check( 'one that cannot be matched, still going round at ten rounds: everything after the breaker off, and it ends', $tenth, array( 4, 5, 5, 15, 15, array(), 0, false, false, false, PHP_INT_MIN ) );
check( '  all of it put back when the guard comes off', array( hooked( $WUP, $LOOPY ), hooked( $WUP, $MAGIC ), hooked( $WUP, array( 'Tidy_Meta', 'on_update' ) ), hooked( $WUP, 'late_indexer' ) ), array( 10, 20, 20, 30 ) );
check( '  and the log takes back "everything else still ran"', $LOGGED, array( 'Saving WC #261 kept looping on woocommerce_update_product after TCGiant skipped Loopy_SEO::on_update: the plugin "Magic Hooks" (folder magic-hooks) saved the product again from it as well (Magic_Hooks::__call). TCGiant stopped it after 10 rounds; the product was saved, but what other plugins had still to do on woocommerce_update_product in that save was skipped.' ) );

// Said once in a request.
$LOGGED = array();
TCGiant_Sync_Runtime::guard_loops();
save_product( 262 );
TCGiant_Sync_Runtime::unguard_loops();
Magic_Hooks::$looping = array();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );
remove_action( 'woocommerce_update_product', $MAGIC, 20 );
remove_action( 'woocommerce_update_product', array( 'Tidy_Meta', 'on_update' ), 20 );
remove_action( 'woocommerce_update_product', 'late_indexer', 30 );
check( '  stopped the same way for the next product, and not said again', array( count( $WRITES ), $LOGGED ), array( 15, array() ) );

echo "\nA LOOP THROUGH ANOTHER HOOK, AND ON THE STOCK HOOK\n" . str_repeat( '=', 126 ) . "\n";

$LOGGED   = array();
$META[301] = array( '_ebay_item_id' => '1301' );
add_action( 'woocommerce_update_product', array( 'Chain_Sync', 'on_update' ), 10, 1 );
add_action( 'save_post', array( 'Post_Saver', 'on_save_post' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 301 );
check( 'wp_update_post() from the product hook, a save from save_post: stopped', array( count( $WRITES ), count( $FINISHED ), $wp_current_filter ), array( 5, 5, array() ) );
check( '  the plugin named is the one on the product hook, not the one on save_post', false !== strpos( $LOGGED[0] ?? '', 'and the plugin in the folder chain-sync saved the product again from it (Chain_Sync::on_update)' ), true );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'Chain_Sync', 'on_update' ), 10 );
remove_action( 'save_post', array( 'Post_Saver', 'on_save_post' ), 10 );

$LOGGED = array();
$META[302] = array( '_parent' => 300 );
$META[300] = array( '_ebay_item_id' => '1300' );
add_action( 'woocommerce_product_set_stock', array( 'Stock_Mirror', 'on_set_stock' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
harness_wc_update_stock( 302 );
TCGiant_Sync_Runtime::unguard_loops();
check( 'a stock write from the stock hook: stopped after five rounds', array( Stock_Mirror::$rounds, $wp_current_filter ), array( 4, array() ) );
check( '  named from its header, with the parent\'s listing for a variation, the hook said to follow a stock change', 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #302 (eBay item 1300) went into a loop: every stock change fired woocommerce_product_set_stock, and the plugin "Stock Mirror" (folder stock-mirror) saved the product again from it (Stock_Mirror::on_set_stock).' ), true );
remove_action( 'woocommerce_product_set_stock', array( 'Stock_Mirror', 'on_set_stock' ), 10 );

$LOGGED = array();
add_action( 'woocommerce_product_object_updated_props', array( 'Props_Watch', 'on_props' ), 10, 2 );
TCGiant_Sync_Runtime::guard_loops();
harness_wc_save_props( 303 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_product_object_updated_props', array( 'Props_Watch', 'on_props' ), 10 );
check( 'a save from woocommerce_product_object_updated_props: stopped after five rounds', array( Props_Watch::$rounds, $wp_current_filter, 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #303 went into a loop: every save fired woocommerce_product_object_updated_props, and the plugin in the folder props-watch saved the product again from it (Props_Watch::on_props).' ) ), array( 4, array(), true ) );

echo "\nWHO IS NAMED\n" . str_repeat( '=', 126 ) . "\n";

$LOGGED = array();
add_action( 'woocommerce_update_product', 'shopfront_touch_product', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 401 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', 'shopfront_touch_product', 10 );
check( 'a theme, by its folder', 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #401 went into a loop: every save fired woocommerce_update_product, and the theme in the folder shopfront saved the product again from it (shopfront_touch_product). TCGiant stopped it after 5 rounds by skipping shopfront_touch_product for the rest of that save; the product was saved, and everything else on woocommerce_update_product still ran. This loop is in the theme in the folder shopfront, not in TCGiant' ), true );

$LOGGED = array();
add_action( 'woocommerce_update_product', 'rogue_touch_product', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 402 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', 'rogue_touch_product', 10 );
check( 'code outside any plugin or theme folder: said so, and how to find it', 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #402 went into a loop: every save fired woocommerce_update_product, and something hooked to it saved the product again (rogue_touch_product), from code outside any plugin or theme folder. TCGiant stopped it after 5 rounds by skipping rogue_touch_product for the rest of that save; the product was saved, and everything else on woocommerce_update_product still ran. It can slow every product save on this site - deactivating plugins one at a time' ), true );

// This plugin's own code is not blamed on anyone else.
function harness_own_resave( $id ) {
	harness_wc_save( $id );
}
$LOGGED = array();
add_action( 'woocommerce_update_product', 'harness_own_resave', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 403 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', 'harness_own_resave', 10 );
check( 'this plugin\'s own code, if it ever did: said so, and asked to be reported', 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #403 went into a loop: every save fired woocommerce_update_product, and TCGiant itself saved the product again from it (harness_own_resave). TCGiant stopped it after 5 rounds by skipping harness_own_resave for the rest of that save; the product was saved, and everything else on woocommerce_update_product still ran. Please send this log to TCGiant support.' ), true );

// But where its code only passed through on the way to another plugin's
// save, the other plugin is the one named.
function harness_own_relay( $id ) {
	Post_Saver::touch( $id );
}
$LOGGED = array();
add_action( 'woocommerce_update_product', 'harness_own_relay', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 404 );
$relay_left = hooked( $WUP, 'harness_own_relay' );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', 'harness_own_relay', 10 );
check( '  a plugin\'s save reached through it: that plugin named, not this one - and the callback that led there the one skipped', array( false !== strpos( $LOGGED[0] ?? '', 'and the plugin in the folder post-saver saved the product again from it (Post_Saver::touch). TCGiant stopped it after 5 rounds by skipping harness_own_relay for the rest of that save' ), $relay_left ), array( true, false ) );

// WooCommerce's own code is in every round - its save fires the hook - and
// is passed over for the plugin that called it. Only when the callback that
// saved again is WooCommerce's own is WooCommerce named.
$LOGGED = array();
add_action( 'woocommerce_update_product', array( 'WC_Internal_Resaver', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 405 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'WC_Internal_Resaver', 'on_update' ), 10 );
check( 'WooCommerce\'s own callback: WooCommerce named, and asked to be reported', $LOGGED[0] ?? '', 'Saving WC #405 went into a loop: every save fired woocommerce_update_product, and WooCommerce itself saved the product again from it (WC_Internal_Resaver::on_update). TCGiant stopped it after 5 rounds by skipping WC_Internal_Resaver::on_update for the rest of that save; the product was saved, and everything else on woocommerce_update_product still ran. Please send this log to TCGiant support.' );

// A theme in a folder of themes a plugin or the host registered.
$LOGGED               = array();
$wp_theme_directories = array( WP_CONTENT_DIR . '/themes', WP_CONTENT_DIR . '/extra-themes' );
add_action( 'woocommerce_update_product', 'kids_shop_touch_product', 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 406 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', 'kids_shop_touch_product', 10 );
$wp_theme_directories = null;
check( 'a theme in a folder registered with register_theme_directory(): by its folder', false !== strpos( $LOGGED[0] ?? '', 'and the theme in the folder kids-shop saved the product again from it (kids_shop_touch_product).' ), true );

// WordPress.com's managed plugins: the real files outside the site, in
// /wordpress/plugins/<name>/<version>, and the folder in wp-content/plugins
// a link to them that WordPress records in $wp_plugin_paths.
$LOGGED          = array();
$wp_plugin_paths = array(
	WP_PLUGIN_DIR . '/managed-seo' => $WPCOM . '/wordpress/plugins/managed-seo/2.1',
	WP_PLUGIN_DIR . '/linked-feed' => $WPCOM . '/vendor/acme/linked-feed',
);
add_action( 'woocommerce_update_product', array( 'Managed_SEO', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 407 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'Managed_SEO', 'on_update' ), 10 );
check( 'a WordPress.com managed plugin, linked into the plugins folder: by its name and folder', 0 === strpos( $LOGGED[0] ?? '', 'Saving WC #407 went into a loop: every save fired woocommerce_update_product, and the plugin "Managed SEO" (folder managed-seo) saved the product again from it (Managed_SEO::on_update). TCGiant stopped it after 5 rounds by skipping Managed_SEO::on_update for the rest of that save;' ), true );

// One linked in from a folder not called plugins - a Composer-built site's -
// which only the link names. An object's own method, too.
$LOGGED = array();
$FEED   = array( new Linked_Feed(), 'on_update' );
add_action( 'woocommerce_update_product', $FEED, 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 408 );
$feed_left = hooked( $WUP, $FEED );
TCGiant_Sync_Runtime::unguard_loops();
$feed_back = hooked( $WUP, $FEED );
remove_action( 'woocommerce_update_product', $FEED, 10 );
check( '  one linked in from elsewhere, an object\'s method: by its folder, and taken off and put back', array( false !== strpos( $LOGGED[0] ?? '', 'and the plugin in the folder linked-feed saved the product again from it (Linked_Feed::on_update). TCGiant stopped it after 5 rounds by skipping Linked_Feed::on_update' ), $feed_left, $feed_back ), array( true, false, 10 ) );

// And one WordPress.com loads from there itself, with no link at all.
$LOGGED = array();
add_action( 'woocommerce_update_product', array( 'Wpcom_Tools', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 409 );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'Wpcom_Tools', 'on_update' ), 10 );
$wp_plugin_paths = array();
check( '  and one loaded from there with no link: by the folder it is in', false !== strpos( $LOGGED[0] ?? '', 'and the plugin in the folder wpcom-tools saved the product again from it (Wpcom_Tools::on_update).' ), true );

echo "\nCALLBACKS THE BACKTRACE NAMES ANOTHER WAY\n" . str_repeat( '=', 126 ) . "\n";

// A closure is told by where it is written; a second closure on the hook,
// after it, is left on and runs every round.
$LOGGED = array();
add_action( 'woocommerce_update_product', Odd_Callbacks::$loops, 10, 1 );
add_action( 'woocommerce_update_product', Odd_Callbacks::$after, 20, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 410 );
$odd = array( hooked( $WUP, Odd_Callbacks::$loops ), hooked( $WUP, Odd_Callbacks::$after ), Odd_Callbacks::$after_runs, count( $WRITES ) );
TCGiant_Sync_Runtime::unguard_loops();
$odd[] = hooked( $WUP, Odd_Callbacks::$loops );
remove_action( 'woocommerce_update_product', Odd_Callbacks::$loops, 10 );
remove_action( 'woocommerce_update_product', Odd_Callbacks::$after, 20 );
check( 'a closure: only it taken off, the closure after it run every round, and put back', $odd, array( false, 20, 5, 5, 10 ) );
check( '  named as a closure in its plugin, by the class it was made in', false !== strpos( $LOGGED[0] ?? '', 'and the plugin in the folder odd-callbacks saved the product again from it (Odd_Callbacks::{closure}). TCGiant stopped it after 5 rounds by skipping Odd_Callbacks::{closure} for the rest' ), true );

// A method the object's class inherits: the backtrace names the class it is
// written in.
$SHOP = array( new Odd_Shop_Saver(), 'on_update' );
add_action( 'woocommerce_update_product', $SHOP, 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
save_product( 411 );
$odd = array( hooked( $WUP, $SHOP ), hooked( $WUP, $HELD ), count( $WRITES ), count( $FINISHED ) );
TCGiant_Sync_Runtime::unguard_loops();
$odd[] = hooked( $WUP, $SHOP );
remove_action( 'woocommerce_update_product', $SHOP, 10 );
check( '  a method its class inherits: taken off by its parent class\'s name, and put back', $odd, array( false, 10, 5, 5, 10 ) );

echo "\nIT NEVER THROWS\n" . str_repeat( '=', 126 ) . "\n";

// The database goes away while the warning about a new culprit is being put
// together.
$LOGGED               = array();
Seven_Rounds::$rounds = 0;
$THROW                = true;
add_action( 'woocommerce_update_product', array( 'Seven_Rounds', 'on_update' ), 10, 1 );
TCGiant_Sync_Runtime::guard_loops();
$threw = false;
try {
	save_product( 501 );
} catch ( Throwable $e ) {
	$threw = $e->getMessage();
}
$THROW = false;
check( 'a failure while it reports: nothing thrown through WordPress\'s hooks', $threw, false );
check( '  the loop still stopped, every save finished, no hook left running', array( Seven_Rounds::$rounds, count( $FINISHED ), $wp_current_filter ), array( 4, 5, array() ) );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', array( 'Seven_Rounds', 'on_update' ), 10 );

echo "\nA REQUEST THAT DIES ANYWAY\n" . str_repeat( '=', 126 ) . "\n";

// A delta run dies with a hook cut: what still runs on the way out finds the
// callbacks back, and the record names the plugin.
$LOGGED = array();
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );
TCGiant_Sync_Runtime::watch( 'Delta sync' );
TCGiant_Sync_Runtime::guard_loops();
TCGiant_Sync_Runtime::context( 'eBay item 297598275224 (243 of 357)' );
save_product( 101 );
check( 'cut short, and the job dies before turning the breaker off', hooked( 'woocommerce_update_product', $LOOPY ), false );
TCGiant_Sync_Runtime::make_room();
check( '  on the way out the callbacks are put back', hooked( 'woocommerce_update_product', $LOOPY ), 10 );

// And it died in a loop the breaker never saw: thousands deep.
$wp_current_filter = array_merge( array( 'action_scheduler_run_queue', 'tcgiant_sync_fetch_delta_events' ), array_fill( 0, 2400, 'woocommerce_update_product' ) );
TCGiant_Sync_Runtime::on_shutdown();
$wp_current_filter = array();
$death = (string) end( $LOGGED );
check( 'the record says the hook was running inside itself, and how often', false !== strpos( $death, 'woocommerce_update_product was running 2400 times inside itself - a save loop' ), true );
check( '  and names the plugin whose loops were stopped earlier', false !== strpos( $death, 'save loop(s) stopped in this request, the last caused by the plugin "Loopy SEO" (folder loopy-seo) in Loopy_SEO::on_update, on woocommerce_update_product' ), true );
TCGiant_Sync_Runtime::unguard_loops();
remove_action( 'woocommerce_update_product', $LOOPY, 10 );

// A hook that is no product save, running inside itself: said, and not
// called a save loop.
$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
TCGiant_Sync_Runtime::watch( 'Delta sync' );
$wp_current_filter = array_fill( 0, 6, 'the_content' );
TCGiant_Sync_Runtime::on_shutdown();
$wp_current_filter = array();
$death = (string) end( $LOGGED );
check( '  another hook running inside itself: said, but not called a save loop', array( false !== strpos( $death, 'the_content was running 6 times at once - a hook running inside itself' ), false !== strpos( $death, 'the_content was running 6 times inside itself - a save loop' ) ), array( true, false ) );

echo "\nTHE PARENTS WOOCOMMERCE SAVES ON THE WAY OUT\n" . str_repeat( '=', 126 ) . "\n";

// Every variation saved defers a save of its parent to the shutdown action
// (WC_Post_Data::do_deferred_product_sync, priority 10) - after the job that
// saved it has turned the breaker off. As WooCommerce does it, as far as
// hooks go.
function harness_wc_deferred_sync() {
	global $wc_deferred_product_sync;
	$ids                      = (array) $wc_deferred_product_sync;
	$wc_deferred_product_sync = array();
	foreach ( $ids as $id ) {
		harness_wc_save( $id );
	}
}
add_action( 'shutdown', 'harness_wc_deferred_sync', 10 );
add_action( 'woocommerce_update_product', $LOOPY, 10, 1 );

TCGiant_Sync_Runtime::guard_loops();
TCGiant_Sync_Runtime::unguard_loops();
check( 'no variation saved: nothing more on the way out', array( hooked( 'shutdown', array( 'TCGiant_Sync_Runtime', 'guard_loops' ) ), hooked( 'shutdown', array( 'TCGiant_Sync_Runtime', 'unguard_loops' ) ) ), array( false, false ) );

TCGiant_Sync_Runtime::guard_loops();
$wc_deferred_product_sync = array( 600 );
TCGiant_Sync_Runtime::unguard_loops();
$rearmed = array( hooked( 'shutdown', array( 'TCGiant_Sync_Runtime', 'guard_loops' ) ), hooked( 'shutdown', array( 'TCGiant_Sync_Runtime', 'unguard_loops' ) ) );
check( 'a variation saved: the breaker is on again either side of WooCommerce\'s sync of its parent', $rearmed, array( 9, 11 ) );
if ( array( 9, 11 ) === $rearmed ) {
	Loopy_SEO::$rounds = 0;
	$WRITES            = array();
	$FINISHED          = array();
	do_action( 'shutdown' );
	check( '  the parent\'s save stops after five rounds, and the breaker is off after it', array( Loopy_SEO::$rounds, count( $FINISHED ), $wp_current_filter, hooked( 'woocommerce_update_product', $OURS ), hooked( 'woocommerce_update_product', $LOOPY ) ), array( 4, 5, array(), false, 10 ) );
} else {
	check( '  the parent\'s save stops after five rounds, and the breaker is off after it', 'not tried: it would go round for ever', true );
}
remove_action( 'shutdown', 'harness_wc_deferred_sync', 10 );
remove_action( 'woocommerce_update_product', $LOOPY, 10 );

echo "\nJON'S LOOP IN 64 MB (A CHILD PROCESS)\n" . str_repeat( '=', 126 ) . "\n";

$child = function ( $mode, &$code = null ) use ( $SITE, $WP_SRC ) {
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d html_errors=0 -d log_errors=0 ' . escapeshellarg( __FILE__ )
		. ' ' . escapeshellarg( '--child=' . $mode ) . ' ' . escapeshellarg( '--wp=' . $WP_SRC ) . ' ' . escapeshellarg( '--site=' . $SITE . '-' . $mode ) . ' 2>&1', $out, $code );
	return implode( "\n", $out );
};

$alone = $child( 'alone', $alone_code );
check( 'without the breaker, one save never finishes: the request runs out of memory', array( false !== strpos( $alone, 'Fatal error: Allowed memory size of 67108864 bytes exhausted' ), false !== strpos( $alone, 'COMPLETED' ), $alone_code ), array( true, false, 255 ) );
$with = $child( 'breaker', $with_code );
check( 'with it, the save finishes after five rounds', array( false !== strpos( $with, 'COMPLETED: 4 rounds, 5 saves finished' ), false !== strpos( $with, 'Fatal error' ), $with_code ), array( true, false, 0 ) );
check( '  and says which plugin', false !== strpos( $with, 'LOGGED: Saving WC #900 (eBay item 297598275224) went into a loop' ) && false !== strpos( $with, 'the plugin "Loopy SEO" (folder loopy-seo)' ), true );
if ( false === strpos( $with, 'COMPLETED' ) || false === strpos( $alone, 'Fatal error' ) ) {
	echo $alone, "\n", $with, "\n";
}
foreach ( array( '-alone', '-alone-wpcom', '-breaker', '-breaker-wpcom' ) as $copy ) {
	harness_remove( $SITE . $copy );
}

echo "\nWHERE THE PLUGIN TURNS IT ON\n" . str_repeat( '=', 126 ) . "\n";

$importer  = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-importer.php' );
$inventory = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-inventory.php' );
$runtime   = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-runtime.php' );

/** The source of one method. */
function method_body( $code, $name ) {
	$start = strpos( $code, 'function ' . $name . '(' );
	if ( false === $start ) {
		return '';
	}
	$next = strpos( $code, "\n\t/**", $start );
	return substr( $code, $start, false === $next ? null : $next - $start );
}

check( 'the delta run, off in its finally', 1 === preg_match( '/Runtime::guard_loops\(\);\s*TCGiant_Sync_Runtime::guard_cache\(\);\s*try \{\s*\$this->run_delta_events\(\);\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_cache\(\);\s*TCGiant_Sync_Runtime::unguard_loops\(\);/', method_body( $importer, 'fetch_delta_events' ) ), true );
check( 'the full sync scan, off in its finally', 1 === preg_match( '/Runtime::guard_loops\(\);\s*TCGiant_Sync_Runtime::guard_cache\(\);\s*try \{\s*\$this->run_scan_all_pages\(\);\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_cache\(\);\s*TCGiant_Sync_Runtime::unguard_loops\(\);/', method_body( $importer, 'scan_all_pages' ) ), true );
check( 'a single listing\'s import, round its save', 1 === preg_match( '/Runtime::guard_loops\(\);\s*TCGiant_Sync_Runtime::release_memory\(\);\s*TCGiant_Sync_Runtime::guard_cache\(\);\s*try \{\s*\$product_id = \$mapper->save_as_product\( \$product_data \);\s*\} finally \{[^}]*TCGiant_Sync_Runtime::unguard_loops\(\);\s*\}/', method_body( $importer, 'process_item_import' ) ), true );
check( 'order sync\'s stock write for an unlinked product', 1 === preg_match( '/Runtime::guard_loops\(\);\s*try \{\s*\$stock_reduced = wc_update_product_stock\([^;]*;\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_loops\(\);/', method_body( $importer, 'sync_recent_orders' ) ), true );
check( 'the pruner, round every product it zeroes or trashes', 1 === preg_match( '/Runtime::guard_loops\(\);\s*try \{\s*foreach \( \$prune_rows as \$row \) \{.*?wp_trash_post\( \$product_id \);.*?\} finally \{\s*TCGiant_Sync_Runtime::unguard_loops\(\);/s', method_body( $importer, 'prune_orphaned_items' ) ), true );
foreach ( array( 'apply_ended_listing_stock', 'lower_stock_to', 'zero_stock', 'use_ebay_figure' ) as $writer ) {
	check( 'the stock write ' . $writer . '(), for every caller', 1 === preg_match( '/self::begin_ebay_origin\(\);\s*(?:\/\/[^\n]*\s*)*TCGiant_Sync_Runtime::guard_loops\(\);\s*try \{.*?\} finally \{\s*TCGiant_Sync_Runtime::unguard_loops\(\);\s*self::end_ebay_origin\(\);/s', method_body( $inventory, $writer ) ), true );
}
check( 'removing a sold-out product', 1 === preg_match( '/Runtime::guard_loops\(\);\s*try \{\s*wp_trash_post\( \$product_id \);\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_loops\(\);/', method_body( $inventory, 'remove_if_allowed' ) ), true );
// A push to eBay, and the product screen's own saves.
$exporter = file_get_contents( $ROOT . '/includes/class-tcgiant-sync-exporter.php' );
$admin    = file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
$guarded  = '/TCGiant_Sync_Runtime::guard_loops\(\);\s*try \{\s*\$product->save\(\);\s*\} finally \{\s*TCGiant_Sync_Runtime::unguard_loops\(\);\s*\}/';
$push     = method_body( $exporter, 'process_single_push' );
check( 'a push queued from the product screen, round its save', 1 === preg_match( $guarded, method_body( $exporter, 'push_product' ) ), true );
check( 'the push itself, round each of its saves', array( preg_match_all( $guarded, $push ), substr_count( $push, '->save()' ) ), array( 2, 2 ) );
check( 'clearing a product\'s export error, round its save', 1 === preg_match( $guarded, method_body( $admin, 'ajax_clear_export_error' ) ), true );
check( 'linking a product to a listing, round its save', 1 === preg_match( $guarded, method_body( $admin, 'ajax_link_ebay' ) ), true );
check( 'the sale check\'s stock writes go through those', array( false !== strpos( method_body( $importer, 'settle_item' ), 'TCGiant_Sync_Inventory::lower_stock_to(' ), false !== strpos( method_body( $importer, 'settle_ended_listing' ), 'TCGiant_Sync_Inventory::apply_ended_listing_stock(' ), false !== strpos( method_body( $importer, 'settle_ended_listing' ), 'TCGiant_Sync_Inventory::maybe_remove_sold_out(' ) ), array( true, true, true ) );

check( 'five rounds', TCGiant_Sync_Runtime::LOOP_DEPTH, 5 );
check( 'the hooks a product save fires that a save can go round on', TCGiant_Sync_Runtime::LOOP_HOOKS, array(
	'woocommerce_update_product',
	'woocommerce_update_product_variation',
	'woocommerce_new_product',
	'woocommerce_new_product_variation',
	'woocommerce_before_product_object_save',
	'woocommerce_after_product_object_save',
	'woocommerce_product_object_updated_props',
	'save_post',
	'save_post_product',
	'save_post_product_variation',
	'woocommerce_product_set_stock',
	'woocommerce_variation_set_stock',
) );

// The plugin cannot be the one going round: it registers nothing on any of
// these but the breaker, and its own stock listeners save nothing.
$registered = array();
foreach ( file( $ROOT . '/tools/hooks.txt' ) as $row ) {
	if ( preg_match( '/^add_(?:action|filter)\s+(\S+) => (\S+)/', $row, $m ) && in_array( $m[1], TCGiant_Sync_Runtime::LOOP_HOOKS, true ) ) {
		$registered[] = $m[1] . ' => ' . $m[2];
	}
}
check( 'the plugin\'s own callbacks on those hooks: the stock listeners only', $registered, array( 'woocommerce_product_set_stock => on_product_stock_change', 'woocommerce_variation_set_stock => on_variation_stock_change' ) );
$listeners = method_body( $inventory, 'on_product_stock_change' ) . method_body( $inventory, 'on_variation_stock_change' ) . method_body( $inventory, 'queue_stock_update_for_product' );
check( '  which save nothing: they queue a push to eBay, at most', array( false === strpos( $listeners, '->save(' ), false === strpos( $listeners, 'wc_update_product_stock' ), false === strpos( $listeners, 'wp_update_post' ), false !== strpos( $listeners, 'as_enqueue_async_action' ) ), array( true, true, true, true ) );

harness_remove( $SITE );
harness_remove( $WPCOM );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
