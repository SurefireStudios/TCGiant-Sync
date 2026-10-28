<?php
/**
 * Harness: the eBay token refresh, and the relay's hand-back of a new
 * connection, write only the connection's own keys, onto the settings as the
 * database holds them; and a refreshed token is kept only for the connection
 * it renews (3.28.0).
 *
 * Until 3.28.0 TCGiant_Sync_OAuth::refresh_access_token() wrote back the
 * whole option as this request had read it. A refresh comes late in a long
 * request - a sync batch, cron - and after a relay call that can take four
 * routes of 30 seconds each, so what another request saved meanwhile was
 * put back as it was: "Send website stock changes to eBay?" set to No (the
 * pushes started again), a Disconnect (the tokens came back, and the
 * disconnect stamp went), a connection to another eBay account (the old
 * account's tokens came back).
 *
 * Loaded for real: TCGiant_Sync_OAuth, its relay answered here at
 * wp_remote_post(); TCGiant_Sync_Inventory's row read and write-back and its
 * disconnect stamp. On tests/support/settings-world.php, each request with
 * its own copy of the options as it began (in_one_request()).
 *
 * @package TCGiant_Sync
 */

$GLOBALS['TCG_REAL_OAUTH'] = true;
require __DIR__ . '/support/settings-world.php';

// --- the relay ------------------------------------------------------------------

// Each call is answered by the next answer in turn; 'during' runs while the
// relay answers - another request's save, made while the call is out.
$GLOBALS['RELAY'] = array( 'answers' => array(), 'calls' => array(), 'during' => null );
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['RELAY']['calls'][] = $args['body'] ?? null;
	if ( is_callable( $GLOBALS['RELAY']['during'] ) ) {
		$during                      = $GLOBALS['RELAY']['during'];
		$GLOBALS['RELAY']['during'] = null;
		$during();
	}
	$answer = array_shift( $GLOBALS['RELAY']['answers'] );
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $answer ), 'headers' => array() );
}
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return ''; }
function wp_remote_retrieve_headers( $r ) { return array(); }

// --- the shop -----------------------------------------------------------------------

// The disconnect stamp's hook, as the inventory class's constructor adds it.
add_action( 'update_option_tcgiant_sync_ebay_settings', array( 'TCGiant_Sync_Inventory', 'connection_saved' ), 10, 2 );

$CONNECTED = array(
	'access_token'  => 'AT-OLD',
	'refresh_token' => 'RT-1',
	'token_expiry'  => (string) ( time() + 60 ),   // Due: within the five minutes get_access_token() allows.
	'relay_secret'  => 'RS-1',
	'marketplace'   => 'EBAY_US',
	'log_level'     => 'info',
);

/** A shop connected to eBay, its token due for renewal, and a relay that renews it. */
function shop( array $settings ) {
	$GLOBALS['W']['options']    = array( 'tcgiant_sync_ebay_settings' => $settings );
	$GLOBALS['W']['autoload']   = array();
	$GLOBALS['W']['transients'] = array();
	$GLOBALS['W']['log']        = array();
	$GLOBALS['W']['cache']      = null;
	$GLOBALS['RELAY']           = array( 'answers' => array( array( 'access_token' => 'AT-NEW', 'expires_in' => 7200 ) ), 'calls' => array(), 'during' => null );
}
function settings() { return $GLOBALS['W']['options']['tcgiant_sync_ebay_settings'] ?? array(); }
function key_of( $key ) { return array_key_exists( $key, settings() ) ? settings()[ $key ] : '<absent>'; }
/** A long request - a sync batch - whose next API call finds the token due, while $meanwhile is saved by another request. */
function long_request_refreshes( $meanwhile = null ) {
	$GLOBALS['RELAY']['during'] = $meanwhile;
	return in_one_request( function () { return TCGiant_Sync_OAuth::instance()->get_access_token(); } );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA REFRESH RENEWS THE TOKEN, AND WRITES NOTHING ELSE\n" . str_repeat( '=', 124 ) . "\n";

shop( $CONNECTED );
$token = long_request_refreshes();
check( 'a due token is renewed: the relay asked with the stored refresh token, the new token saved and handed back', array( count( $GLOBALS['RELAY']['calls'] ), $GLOBALS['RELAY']['calls'][0]['refresh_token'] ?? null, $token, key_of( 'access_token' ) ), array( 1, 'RT-1', 'AT-NEW', 'AT-NEW' ) );
check( '  with its new expiry; the rest as it was', array( (int) key_of( 'token_expiry' ) > time() + 7000, key_of( 'refresh_token' ), key_of( 'relay_secret' ), key_of( 'log_level' ) ), array( true, 'RT-1', 'RS-1', 'info' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT ANOTHER REQUEST SAVED WHILE THE RELAY ANSWERED STANDS\n" . str_repeat( '=', 124 ) . "\n";

// "Send website stock changes to eBay?" set to No elsewhere.
shop( $CONNECTED );
$token = long_request_refreshes( function () { other_request_saves( 'push_stock_to_ebay', '0' ); other_request_saves( 'log_level', 'error' ); } );
check( 'No saved by another request during the refresh: it stands, and pushes stay off', array( key_of( 'push_stock_to_ebay' ), TCGiant_Sync_Inventory::pushes_enabled( TCGiant_Sync_Inventory::settings_row() ), key_of( 'log_level' ) ), array( '0', false, 'error' ) );
check( '  and the new token is saved all the same', array( $token, key_of( 'access_token' ) ), array( 'AT-NEW', 'AT-NEW' ) );

// Disconnect, through the real option save (the stamp is written by its hook).
shop( $CONNECTED );
$token = long_request_refreshes( function () {
	$s = $GLOBALS['W']['options']['tcgiant_sync_ebay_settings'];
	unset( $s['access_token'], $s['refresh_token'], $s['token_expiry'] );
	$copy                  = $GLOBALS['W']['cache'];
	$GLOBALS['W']['cache'] = null;   // The other request's own write.
	update_option( 'tcgiant_sync_ebay_settings', $s );
	$GLOBALS['W']['cache'] = $copy;
} );
check( 'Disconnect during the refresh: the tokens stay gone, the new one is dropped', array( key_of( 'access_token' ), key_of( 'refresh_token' ), $token ), array( '<absent>', '<absent>', false ) );
check( '  and the disconnect stamp stays', false !== get_option( TCGiant_Sync_Inventory::DISCONNECTED_OPTION, false ), true );
check( '  said in one line', count( array_filter( $GLOBALS['W']['log'], function ( $l ) { return false !== strpos( $l[1], 'the eBay connection changed while the new token was on its way' ); } ) ), 1 );

// Another eBay account connected.
shop( $CONNECTED );
$token = long_request_refreshes( function () { other_request_saves( 'access_token', 'AT-OTHER' ); other_request_saves( 'refresh_token', 'RT-OTHER' ); other_request_saves( 'token_expiry', (string) ( time() + 7200 ) ); } );
check( 'another account connected during the refresh: its connection stands, and its token is the one handed back', array( key_of( 'refresh_token' ), key_of( 'access_token' ), $token ), array( 'RT-OTHER', 'AT-OTHER', 'AT-OTHER' ) );
// The relay's fleet budget came with the dropped token: kept all the same
// (3.28.0 integration: item D's snapshot, item 17's compare-and-set).
shop( $CONNECTED );
$GLOBALS['RELAY']['answers'] = array( array( 'access_token' => 'AT-NEW', 'expires_in' => 7200, 'remaining_budget' => 30000, 'global_api_calls' => 12000, 'daily_limit' => 50000 ) );
long_request_refreshes( function () { other_request_saves( 'access_token', 'AT-OTHER' ); other_request_saves( 'refresh_token', 'RT-OTHER' ); other_request_saves( 'token_expiry', (string) ( time() + 7200 ) ); } );
$tcg_budget = $GLOBALS['W']['transients'][ 'tcgiant_relay_budget_' . gmdate( 'Y-m-d' ) ] ?? array();
check( '  the fleet budget that came with the dropped token is kept, with this site\'s count as sent', array( key_of( 'access_token' ), $tcg_budget['global_calls'] ?? null, $tcg_budget['daily_limit'] ?? null, array_key_exists( 'local_calls', $tcg_budget ) ), array( 'AT-OTHER', 12000, 50000, true ) );
// And the rest of the request uses the connection as it is (SW3-L1): its copy is dropped.
shop( $CONNECTED );
$GLOBALS['RELAY']['during'] = function () { other_request_saves( 'access_token', 'AT-OTHER' ); other_request_saves( 'refresh_token', 'RT-OTHER' ); other_request_saves( 'token_expiry', (string) ( time() + 7200 ) ); };
$tcg_seen = in_one_request( function () {
	$first                     = TCGiant_Sync_OAuth::instance()->get_access_token();
	$GLOBALS['W']['row_reads'] = array();
	return array( $first, TCGiant_Sync_OAuth::instance()->get_access_token(), $GLOBALS['W']['row_reads'] );
} );
check( '  the next call in that request uses the other account\'s token too, from its own copy now: no row read, the relay asked once', array( $tcg_seen, count( $GLOBALS['RELAY']['calls'] ) ), array( array( 'AT-OTHER', 'AT-OTHER', array() ), 1 ) );

// A refresh started after a Disconnect another request saved: nothing to renew.
shop( $CONNECTED );
$token = in_one_request( function () {
	other_request_saves( 'access_token', null );
	other_request_saves( 'refresh_token', null );
	return TCGiant_Sync_OAuth::instance()->get_access_token();
} );
check( 'a refresh begun after another request disconnected: the relay is not asked, the tokens stay gone', array( $token, count( $GLOBALS['RELAY']['calls'] ), key_of( 'access_token' ) ), array( false, 0, '<absent>' ) );

// Another account connected before this request's refresh began: renewed with its refresh token.
shop( $CONNECTED );
$token = in_one_request( function () {
	other_request_saves( 'refresh_token', 'RT-OTHER' );
	return TCGiant_Sync_OAuth::instance()->get_access_token();
} );
check( '  one begun after another account was connected: renewed with that account\'s refresh token', array( $GLOBALS['RELAY']['calls'][0]['refresh_token'] ?? null, $token, key_of( 'access_token' ), key_of( 'refresh_token' ) ), array( 'RT-OTHER', 'AT-NEW', 'AT-NEW', 'RT-OTHER' ) );

// Another account connected, or the token renewed, by another request before this one's refresh: that token is used.
shop( $CONNECTED );
$token = in_one_request( function () {
	other_request_saves( 'access_token', 'AT-OTHER' );
	other_request_saves( 'refresh_token', 'RT-OTHER' );
	other_request_saves( 'token_expiry', (string) ( time() + 7200 ) );
	return TCGiant_Sync_OAuth::instance()->get_access_token();
} );
check( '  one that finds another account connected since, its token not due: that token, the relay not asked, the connection as it is', array( $token, count( $GLOBALS['RELAY']['calls'] ), key_of( 'access_token' ), key_of( 'refresh_token' ) ), array( 'AT-OTHER', 0, 'AT-OTHER', 'RT-OTHER' ) );
// That token becomes this request's own (SS3-L1): refused by eBay, it is renewed; the rest of the request uses the renewal.
shop( $CONNECTED );
$GLOBALS['RELAY']['answers'] = array( array( 'access_token' => 'AT-3', 'expires_in' => 7200 ) );
$tcg_seen = in_one_request( function () {
	other_request_saves( 'access_token', 'AT-2' );
	other_request_saves( 'token_expiry', (string) ( time() + 7200 ) );
	$first  = TCGiant_Sync_OAuth::instance()->get_access_token();
	$forced = TCGiant_Sync_OAuth::instance()->refresh_access_token();   // The API's forced refresh: eBay refused AT-2.
	return array( $first, $forced, TCGiant_Sync_OAuth::instance()->get_access_token() );
} );
check( '    and refused by eBay in turn, it is renewed, and the rest of the request uses the renewal', array( $tcg_seen, count( $GLOBALS['RELAY']['calls'] ), key_of( 'access_token' ) ), array( array( 'AT-2', 'AT-3', 'AT-3' ), 1, 'AT-3' ) );
shop( $CONNECTED );
$token = in_one_request( function () {
	other_request_saves( 'access_token', 'AT-RENEWED' );
	other_request_saves( 'token_expiry', (string) ( time() + 7200 ) );
	return TCGiant_Sync_OAuth::instance()->get_access_token();
} );
check( '  one that finds the token renewed by another request since: that token, the relay not asked', array( $token, count( $GLOBALS['RELAY']['calls'] ), key_of( 'access_token' ) ), array( 'AT-RENEWED', 0, 'AT-RENEWED' ) );
shop( $CONNECTED );
$token = in_one_request( function () {
	other_request_saves( 'access_token', 'AT-OTHER' );
	other_request_saves( 'refresh_token', 'RT-OTHER' );
	other_request_saves( 'token_expiry', (string) ( time() + 60 ) );
	return TCGiant_Sync_OAuth::instance()->get_access_token();
} );
check( '  one that finds another account connected since, its token due too: renewed with that account\'s refresh token', array( $GLOBALS['RELAY']['calls'][0]['refresh_token'] ?? null, $token, key_of( 'access_token' ) ), array( 'RT-OTHER', 'AT-NEW', 'AT-NEW' ) );
// eBay refuses the token this request holds, not yet due by its expiry: it is renewed all the same.
shop( array_merge( $CONNECTED, array( 'token_expiry' => (string) ( time() + 7200 ) ) ) );
$token = in_one_request( function () { return TCGiant_Sync_OAuth::instance()->refresh_access_token(); } );
check( '  a token eBay refused, not due by its expiry: renewed', array( $token, count( $GLOBALS['RELAY']['calls'] ), key_of( 'access_token' ) ), array( 'AT-NEW', 1, 'AT-NEW' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE RELAY'S HAND-BACK OF A NEW CONNECTION, THE SAME\n" . str_repeat( '=', 124 ) . "\n";

shop( array( 'marketplace' => 'EBAY_US', 'log_level' => 'info' ) );
in_one_request( function () {
	other_request_saves( 'push_stock_to_ebay', '0' );
	TCGiant_Sync_OAuth::instance()->save_tokens_from_relay( array( 'access_token' => 'AT-C', 'refresh_token' => 'RT-C', 'expires_in' => 7200, 'relay_key' => 'RS-C' ) );
} );
check( 'the connection saved, onto the settings as the database holds them: a No saved meanwhile stands', array( key_of( 'access_token' ), key_of( 'refresh_token' ), key_of( 'relay_secret' ), key_of( 'push_stock_to_ebay' ) ), array( 'AT-C', 'RT-C', 'RS-C', '0' ) );

done();
