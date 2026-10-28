<?php
/**
 * Run uninstall.php in a WordPress of its own and print, as JSON, the options
 * it deleted, the WP-Cron hooks it cleared and the Action Scheduler hooks it
 * unscheduled - for a harness whose own world already declares the functions
 * uninstall.php calls (test-reconcile.php). A process of its own.
 *
 * usage: php tests/support/uninstall-run.php <1|0>   whether the shop asked for its data to go
 *
 * @package TCGiant_Sync
 */

define( 'WP_UNINSTALL_PLUGIN', true );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/tcg-uninstall-run-none' );

$GLOBALS['U']    = array( 'deleted' => array(), 'cleared' => array(), 'unscheduled' => array() );
$GLOBALS['DATA'] = isset( $argv[1] ) && '1' === $argv[1];

function get_option( $name, $default = false ) {
	return 'tcgiant_sync_ebay_settings' === $name ? array( 'delete_data_on_uninstall' => $GLOBALS['DATA'] ? '1' : '0' ) : $default;
}
function delete_option( $name ) { $GLOBALS['U']['deleted'][] = $name; return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['U']['cleared'][] = $hook; return 0; }
function as_unschedule_all_actions( $hook ) { $GLOBALS['U']['unscheduled'][] = $hook; }

class Uninstall_Run_Wpdb {
	public $options  = 'wp_options';
	// 3.27.8's uninstall clears the WP-Lister notice's dismissal per user.
	public $usermeta = 'wp_usermeta';
	public $prefix   = 'wp_';
	public function query( $sql ) { return 0; }
	public function get_var( $sql ) { return '0'; }
}
$wpdb = new Uninstall_Run_Wpdb();

include dirname( __DIR__, 2 ) . '/uninstall.php';

echo json_encode( $GLOBALS['U'] );
