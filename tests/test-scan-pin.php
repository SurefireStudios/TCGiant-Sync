<?php
/**
 * Harness: what the full sync's scan sends eBay for each page.
 *
 * Every page of one scan asks for listings ending from the moment the scan
 * began, for 120 days. It asked from "now" on every page. Loads the real
 * TCGiant_Sync_API and captures the GetSellerList body.
 *
 * @package TCGiant_Sync
 */

$pass = 0; $fail = 0;
function check( $l, $g, $w ) { global $pass, $fail; $ok = $g === $w; $ok ? $pass++ : $fail++; printf( "  %s  %-60s got %-28s want %s\n", $ok ? 'PASS' : 'FAIL', $l, var_export( $g, true ), var_export( $w, true ) ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
function add_action() {} function esc_attr( $t ) { return $t; } function __( $t ) { return $t; }
require dirname( __DIR__ ) . '/includes/class-tcgiant-sync-api.php';
class Capture_API extends TCGiant_Sync_API { public $sent = array(); public function trading_api_request( $call, $xml, $retry = true, $max = 0 ) { $this->sent[] = array( $call, $xml ); return array(); } }
$api = new Capture_API();
$api->get_active_listings( 7, 200, '', '2026-10-12T09:00:00.000Z' );
preg_match( '#<EndTimeFrom>([^<]+)</EndTimeFrom>#', $api->sent[0][1], $f ); preg_match( '#<EndTimeTo>([^<]+)</EndTimeTo>#', $api->sent[0][1], $t );
check( 'page 7 of a scan started at 09:00 asks from 09:00', $f[1] ?? '', '2026-10-12T09:00:00.000Z' );
check( '  to 120 days after that', $t[1] ?? '', '2027-02-09T09:00:00.000Z' );
$api->get_active_listings( 1, 200, '2026-10-10T00:00:00.000Z', '2026-10-12T09:00:00.000Z' );
check( 'a ModTime scan sends no EndTime at all', false === strpos( $api->sent[1][1], 'EndTime' ), true );
$api->get_active_listings( 1, 200 );
preg_match( '#<EndTimeFrom>([^<]+)</EndTimeFrom>#', $api->sent[2][1], $n );
check( 'no pin: from now, as before', abs( strtotime( $n[1] ?? '' ) - time() ) < 5, true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
