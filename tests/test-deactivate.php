<?php
/**
 * Harness: deactivating the plugin while WooCommerce is not active.
 *
 * tcgiant_sync_init() returns before the core class registers its autoloader
 * when WooCommerce is missing, so the deactivation hook can rely on nothing
 * it does not load itself. It named TCGiant_Sync_Image_Localizer, and from
 * 3.27.4 TCGiant_Sync_Photo_Cleanup, through the cron's deactivate(): a fatal
 * error that left the plugin active - WordPress saves the list of active
 * plugins after the hook - and the image batch's and the photo cleanup's
 * schedules in place. Shops do this when they turn plugins off one at a time
 * to find a fault.
 *
 * This loads the real tcgiant-sync.php, runs plugins_loaded without
 * WooCommerce, and then runs the deactivation hook as WordPress does.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %s\n", $ok ? 'PASS' : 'FAIL', $label, substr( str_replace( "\n", ' ', var_export( $got, true ) ), 0, 160 ) );
}

$ROOT = str_replace( '\\', '/', getenv( 'TCG_ROOT' ) ?: dirname( __DIR__ ) );

define( 'ABSPATH', sys_get_temp_dir() . '/' );

$ACTIONS       = array();
$CLEARED       = array();
$ON_DEACTIVATE = null;

function plugin_dir_path( $file ) { return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://shop.example/wp-content/plugins/tcgiant-sync/'; }
function plugin_basename( $file ) { return 'tcgiant-sync/tcgiant-sync.php'; }
function wp_get_environment_type() { return 'production'; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['ACTIONS'][ $hook ][] = $callback; return true; }
function register_deactivation_hook( $file, $callback ) { $GLOBALS['ON_DEACTIVATE'] = $callback; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['CLEARED'][] = $hook; return 0; }
function esc_html_e( $text ) {}

echo "\nDEACTIVATED WITH WOOCOMMERCE INACTIVE\n" . str_repeat( '=', 110 ) . "\n";

require $ROOT . '/tcgiant-sync.php';

// plugins_loaded, with no WooCommerce class.
foreach ( $ACTIONS['plugins_loaded'] ?? array() as $callback ) {
	call_user_func( $callback );
}
check( 'the plugin did not start, so no autoloader is registered', class_exists( 'TCGiant_Sync', false ), false );

$error = '';
try {
	call_user_func( $ON_DEACTIVATE );
} catch ( \Throwable $e ) {
	$error = get_class( $e ) . ': ' . $e->getMessage();
}
check( 'the deactivation hook runs to the end', $error, '' );

foreach ( array( 'tcgiant_sync_poll_ebay_cron', 'tcgiant_sync_scan_resume', 'tcgiant_sync_localize_images', 'tcgiant_sync_photo_cleanup' ) as $hook ) {
	check( "  and clears {$hook}", in_array( $hook, $CLEARED, true ), true );
}

// Every class the cron's deactivate() names must be one the hook loads: the
// next one added there would otherwise bring the fatal back.
$cron = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-cron.php' );
$main = (string) file_get_contents( $ROOT . '/tcgiant-sync.php' );
preg_match( '/public static function deactivate\(\) \{(.*?)\n\t\}/s', $cron, $body );
preg_match_all( '/\b(TCGiant_Sync_[A-Za-z_]+)::/', $body[1] ?? '', $named );
preg_match( '/function tcgiant_sync_deactivate\(\)\s*\{(.*?)\n\t\}/s', $main, $hook );
$missing = array();
foreach ( array_unique( $named[1] ) as $class ) {
	$file = 'includes/class-tcgiant-sync-' . strtolower( str_replace( '_', '-', substr( $class, strlen( 'TCGiant_Sync_' ) ) ) ) . '.php';
	if ( false === strpos( $hook[1] ?? '', "'" . $file . "'" ) ) {
		$missing[] = $class;
	}
}
check( 'every class the cron\'s deactivate() names is loaded by the hook first', array( count( $named[1] ) > 0, $missing ), array( true, array() ) );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
