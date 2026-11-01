<?php
/**
 * One delta request of tests/test-delta-renewal.php's world, in a PHP process
 * of its own, killed by a real out-of-memory fatal while it records a renewal:
 * as it writes the record of the product the world names, or - with
 * kill_after - at the first write after that record, the run's note that the
 * listing is done. What the request left is written back for the test, from
 * the last shutdown function, after the plugin's own.
 *
 * Usage: php renewal-kill.php <world file>
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/renewal-world.php';

$KILL_FILE = (string) ( $argv[1] ?? '' );
$in        = unserialize( (string) file_get_contents( $KILL_FILE ) );

foreach ( $in['globals'] as $name => $value ) {
	$GLOBALS[ $name ] = $value;
}

$KILL_PID   = (int) $in['kill_on'];
$KILL_AFTER = ! empty( $in['kill_after'] );
$KILL_ARMED = false;

/** A real, uncatchable fatal: what a host's memory_limit does. */
function die_now() {
	$GLOBALS['KILL_ON'] = null; // Not again on the way out.
	ini_set( 'memory_limit', '32M' );
	$GLOBALS['KILL_BALLAST'] = str_repeat( 'x', 64 * 1048576 );
}

$KILL_ON = function ( $what, $id, $key ) {
	if ( $GLOBALS['KILL_ARMED'] ) {
		die_now();
	}
	if ( 'meta' === $what && $GLOBALS['KILL_PID'] === (int) $id && '_ebay_event_sig' === $key ) {
		if ( ! $GLOBALS['KILL_AFTER'] ) {
			die_now();
		}
		$GLOBALS['KILL_ARMED'] = true;
	}
};

/** Written last: registered by a shutdown function, so after the plugin's. */
function write_back() {
	$GLOBALS['KILL_ON']      = null;
	$GLOBALS['KILL_BALLAST'] = null;
	$e = error_get_last();
	file_put_contents( $GLOBALS['KILL_FILE'], serialize( array(
		'fatal'   => is_array( $e ) && E_ERROR === (int) $e['type'],
		'globals' => array_intersect_key( $GLOBALS, array_flip( array( 'POSTS', 'META', 'ROWS', 'OPTIONS', 'TRANSIENTS', 'QUEUE', 'EBAY', 'NEXT_ID' ) ) ),
	) ) );
}
register_shutdown_function( function () {
	register_shutdown_function( 'write_back' );
} );

delta_request();
