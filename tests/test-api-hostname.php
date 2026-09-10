<?php
/**
 * Harness: serving the relay from a second hostname without splitting its data.
 *
 * Our host's Imunify360 WebShield challenges requests from addresses its cloud
 * has classified. Merchants on shared or residential connections get classified
 * through nobody's fault - one is on a residential line, another shares a box in
 * Sydney - and a server cannot answer a JavaScript challenge, so those shops
 * could not connect to eBay at all. The host can exempt a hostname, so the API
 * moves to api.tcgiant.com and the main site keeps its protection.
 *
 * The trap in that plan is the database. sync.db is shared by the relay, the
 * dashboard, the token refresh and the deletion fan-out, and every path in those
 * files was __DIR__. Served from a second document root, SQLite would have
 * created a second, empty database without a word, and every shop connecting
 * through the new hostname would have been invisible to everything else.
 *
 * So: the data directory is a constant defaulting to __DIR__, and the loader on
 * the new hostname sets it - refusing to serve at all if it cannot find the real
 * one. This file asserts both halves, and that the dashboard is not served
 * there, since the whole point of the second hostname is that it is exempt.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-64s got %-20s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$site = dirname( __DIR__, 2 ) . '/website';

if ( ! is_dir( $site . '/syncconnect' ) ) {
	echo "\n  SKIPPED - the relay is not in this checkout (it is deployed by hand).\n\n";
	echo "  0 passed, 0 failed\n\n";
	exit( 0 );
}

echo "\nTHE DATA DIRECTORY IS NO LONGER WHEREVER THE CODE HAPPENS TO SIT\n" . str_repeat( '=', 112 ) . "\n";

foreach ( array( 'relay.php', 'telemetry.php' ) as $file ) {
	$body = (string) file_get_contents( $site . '/syncconnect/' . $file );

	check( $file . ': no data path is left as __DIR__', substr_count( $body, "__DIR__ . '/" ), 0 );
	check( '  it reads TCG_DATA_DIR instead', substr_count( $body, "TCG_DATA_DIR . '/" ) > 0, true );
	check( '  which still defaults to this directory', false !== strpos( $body, "define( 'TCG_DATA_DIR', __DIR__ );" ), true );
	check( '  and is only defined when nobody else has', false !== strpos( $body, "if ( ! defined( 'TCG_DATA_DIR' ) ) {" ), true );
}

echo "\nTHE LOADER REFUSES TO GUESS\n" . str_repeat( '=', 112 ) . "\n";

$boot = $site . '/api.tcgiant.com/bootstrap.php';
check( 'the API hostname has a loader', is_file( $boot ), true );

$body = (string) file_get_contents( $boot );

// The load-bearing assertion. Testing for relay.php alone would pass on a
// directory holding a COPY of the code and no database - which is the exact
// mistake that would split the data in two.
check( 'it requires the database, not just the code', false !== strpos( $body, "is_file( \$candidate . '/sync.db' )" ), true );
check( '  and the code as well', false !== strpos( $body, "is_file( \$candidate . '/relay.php' )" ), true );
check( 'it sets the data directory before including anything', strpos( $body, "define( 'TCG_DATA_DIR', \$dir );" ) < strpos( $body, 'require $file;' ), true );
check( 'a directory it cannot find is a refusal, not a fallback', false !== strpos( $body, 'http_response_code( 503 )' ), true );
check( '  and the detail goes to the log, not to the caller', false !== strpos( $body, 'error_log(' ), true );
check( '  the public answer says nothing about paths', false !== strpos( $body, "'error' => 'relay unavailable'" ), true );
check( 'there is an override for when the search is wrong', false !== strpos( $body, 'TCG_RELAY_DIR_OVERRIDE' ), true );

// Nothing secret belongs in a file whose whole job is to be reachable.
foreach ( array( 'bootstrap.php', 'relay.php', 'telemetry.php' ) as $file ) {
	$body = (string) file_get_contents( $site . '/api.tcgiant.com/' . $file );
	$has_secret = false;
	foreach ( array( 'EBAY_CERT_ID', 'RELAY_SECRET', 'DASHBOARD_PASSWORD', 'PRD-', 'EBAY_APP_ID' ) as $needle ) {
		if ( false !== strpos( $body, $needle ) ) { $has_secret = true; }
	}
	check( $file . ' carries no credentials', $has_secret, false );
}

echo "\nTHE DASHBOARD IS NOT SERVED ON THE EXEMPT HOSTNAME\n" . str_repeat( '=', 112 ) . "\n";

$api_rules = (string) file_get_contents( $site . '/api.tcgiant.com/.htaccess' );

check( 'the API hostname denies everything by default', false !== strpos( $api_rules, 'Require all denied' ), true );
check( '  and grants exactly the two machine endpoints', 1 === preg_match( '/\^\(relay\|telemetry\)\\\\\.php\$/', $api_rules ), true );
check( '  the dashboard is not among them', false === strpos( $api_rules, 'dashboard' ) || false !== strpos( $api_rules, 'dashboard is deliberately absent' ), true );
check( '  nor is the loader, which is included and never fetched', false === strpos( $api_rules, '(bootstrap' ), true );

check( 'the main hostname still serves all four', false !== strpos( (string) file_get_contents( $site . '/syncconnect/.htaccess' ), '^(relay|connect|telemetry|dashboard)\.php$' ), true );

echo "\nEBAY'S REGISTERED URL IS NOT ACCIDENTALLY MOVED\n" . str_repeat( '=', 112 ) . "\n";

// eBay hashes this string when it verifies the endpoint, so it has to match the
// developer portal character for character. Moving the code to a new hostname
// must not quietly move this with it.
$relay = (string) file_get_contents( $site . '/syncconnect/relay.php' );

check( 'the notification URL is a named constant', false !== strpos( $relay, "define( 'EBAY_NOTIFY_ENDPOINT'" ), true );
check( '  still pointing at the registered address', false !== strpos( $relay, "'https://tcgiant.com/syncconnect/relay.php' );" ), true );
check( '  and the challenge uses it rather than a literal', false !== strpos( $relay, '$endpoint = EBAY_NOTIFY_ENDPOINT;' ), true );
check( 'the URL appears once, so moving it is one edit', substr_count( $relay, "https://tcgiant.com/syncconnect/relay.php" ), 1 );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
