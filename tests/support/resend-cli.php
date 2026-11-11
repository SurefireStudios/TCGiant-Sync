<?php
/**
 * Re-send to eBay's count under WP-CLI, for tests/test-template-resend.php:
 * WP_CLI is set as WP-CLI sets it, before anything loads, so it runs in a
 * process of its own. The request's time is spent from the start; under
 * WP-CLI no host ends the request, so the count does not stop for it.
 * Prints one line of JSON: the listings counted, the count's actions run,
 * and how often the time budget was asked.
 *
 * @package TCGiant_Sync
 */

define( 'WP_CLI', true );

require __DIR__ . '/resend-world.php';

roomy();
$tpl                             = make_template( array( 'name' => 'Clean', 'html' => '<h1>{{title}}</h1>{{{description}}}' ) );
$settings                        = TCGiant_Sync_Templates::default_settings();
$settings['default_template_id'] = $tpl;
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
for ( $i = 1; $i <= 120; $i++ ) {
	listing( 6000 + $i );
}

TCGiant_Sync_Runtime::$late = '60 s of this request used, of a 60 s budget';
TCGiant_Sync_Template_Resend::queue_scan( 'harness', true );
$actions = as_run( TCGiant_Sync_Template_Resend::SCAN_HOOK );
$done    = get_option( TCGiant_Sync_Template_Resend::COUNT_OPTION, array() )['done'] ?? array();

$GLOBALS['harness_done'] = true;
echo wp_json_encode( array(
	'count'   => (int) ( $done['groups'][ 't' . $tpl ]['count'] ?? 0 ),
	'actions' => $actions,
	'asked'   => TCGiant_Sync_Runtime::$asked,
) ), "\n";
