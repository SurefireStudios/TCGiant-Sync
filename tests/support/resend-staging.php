<?php
/**
 * Re-send to eBay on a staging copy, for tests/test-template-resend.php: the
 * copy's constant is set as wp-config sets it, before anything loads, so it
 * runs in a process of its own. Prints one line of JSON: what Test on 5
 * said, why a Re-send all paused, how many calls it made, and whether the
 * box says why nothing goes.
 *
 * @package TCGiant_Sync
 */

define( 'TCGIANT_SYNC_IS_STAGING', true );

require __DIR__ . '/resend-world.php';

function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce:' . $action . '">'; }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }

roomy();
$tpl                             = make_template( array( 'name' => 'Clean', 'html' => '<h1>{{title}}</h1>{{{description}}}' ) );
$settings                        = TCGiant_Sync_Templates::default_settings();
$settings['default_template_id'] = $tpl;
update_option( TCGiant_Sync_Templates::SETTINGS_OPTION, $settings );
listing( 5001 );
listing( 5002 );
count_now();

$test = TCGiant_Sync_Template_Resend::test( 't' . $tpl );

// A Re-send all already going when the site became a copy.
update_option( TCGiant_Sync_Template_Resend::QUEUE_PREFIX . '0', array( 5001, 5002 ) );
update_option( TCGiant_Sync_Template_Resend::STATE_OPTION, array( 'status' => 'running', 'group' => 't' . $tpl, 'name' => 'Clean', 'template_id' => $tpl, 'version' => 1, 'tpl_hash' => TCGiant_Sync_Templates::get( $tpl )['hash'], 'total' => 2, 'chunks' => 1, 'cursor' => 0, 'sent' => 0, 'failed' => 0, 'attention' => 0, 'dropped' => 0, 'dropped_why' => array(), 'pause' => null ) );
TCGiant_Sync_Template_Resend::run_batch();
$state = TCGiant_Sync_Template_Resend::state();

ob_start();
include TCGIANT_SYNC_PATH . 'admin/views/template-resend.php';
$box = ob_get_clean();

$GLOBALS['harness_done'] = true;
echo wp_json_encode( array(
	'test'  => $test['message'],
	'why'   => $state['pause']['why'] ?? '',
	'calls' => count( $GLOBALS['API']->calls ),
	'box'   => false !== strpos( $box, 'This site looks like a staging or development copy' ) && false !== strpos( $box, 'Nothing can be sent from this site now.' ),
) ), "\n";
