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
check( 'it sets the data directory before handing the path back', strpos( $body, "define( 'TCG_DATA_DIR', \$dir );" ) < strpos( $body, 'return $file;' ), true );
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

echo "\nTHE RELAY IS INCLUDED WHERE IT THINKS IT IS\n" . str_repeat( '=', 112 ) . "\n";

// This is the one that cost six days of broken connections.
//
// relay.php opens its database at its own top level and four handlers reach it
// with `global $db;`. PHP runs an included file's top level in the scope of
// whatever included it, so requiring relay.php from INSIDE a function made that
// handle a local variable of the function and all four handlers found null.
//
// Nothing crashed, which is why nobody noticed. handle_token_claim() answered
// every seller HTTP 400 {"error":"invalid_request"} - its reply to a malformed
// request - so no eBay account could finish connecting on this hostname and the
// reason given pointed at the seller's own server. handle_token_refresh()
// guards its writes with `if ( $db ... )`, so existing shops kept working while
// last_connected and the API call counts silently stopped being recorded.

$boot_body = (string) file_get_contents( $site . '/api.tcgiant.com/bootstrap.php' );

check( 'the loader resolves a path and does not include it', false === strpos( $boot_body, 'require $file;' ), true );
check( '  it hands the path back instead', false !== strpos( $boot_body, 'return $file;' ), true );

foreach ( array( 'relay.php', 'telemetry.php' ) as $file ) {
	$loader = (string) file_get_contents( $site . '/api.tcgiant.com/' . $file );

	// Strip comments first: the explanation of this very fault names the thing
	// it warns about, and must not be able to answer for the code.
	$code = php_strip_whitespace( $site . '/api.tcgiant.com/' . $file );

	check( $file . ': it requires the endpoint', false !== strpos( $code, "tcg_api_target( '" ), true );
	check( '  at file scope, never from inside a function', false === strpos( $code, 'function ' ), true );
	check( '  and says why, so nobody moves it back', false !== strpos( $loader, 'file scope' ), true );
}

// The stake, stated in the test rather than left to a comment: these are the
// handlers that go wrong when the handle is invisible.
$relay_body = (string) file_get_contents( $site . '/syncconnect/relay.php' );

check( 'the relay still reaches its handle through global', substr_count( $relay_body, 'global $db;' ), 4 );
check( '  and still opens it at its own top level', false !== strpos( $relay_body, "\$db = new SQLite3( TCG_DATA_DIR . '/sync.db' );" ), true );

// The behaviour itself, run rather than described. A stand-in for relay.php,
// included both ways, with the real question asked of each.
$tmp = sys_get_temp_dir() . '/tcg-scope-' . getmypid();
@mkdir( $tmp, 0777, true );

file_put_contents( $tmp . '/inner.php', "<?php\n\$db = 'live handle';\nfunction reaches_it() { global \$db; return null === \$db ? 'invisible' : 'live handle'; }\n" );
file_put_contents( $tmp . '/from_function.php', "<?php\nfunction loader( \$f ) { require \$f; }\nloader( __DIR__ . '/inner.php' );\necho reaches_it();\n" );
file_put_contents( $tmp . '/from_file_scope.php', "<?php\nrequire __DIR__ . '/inner.php';\necho reaches_it();\n" );

$php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';

check( 'required from inside a function, the handle is lost', trim( (string) shell_exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $tmp . '/from_function.php' ) . ' 2>&1' ) ), 'invisible' );
check( '  required at file scope, it is there', trim( (string) shell_exec( escapeshellarg( $php ) . ' ' . escapeshellarg( $tmp . '/from_file_scope.php' ) . ' 2>&1' ) ), 'live handle' );

foreach ( array( 'inner.php', 'from_function.php', 'from_file_scope.php' ) as $leftover ) {
	@unlink( $tmp . '/' . $leftover );
}
@rmdir( $tmp );


echo "\nTHE SERVICE SAYS WHOSE FAULT IT IS\n" . str_repeat( '=', 112 ) . "\n";

// The six days were not lost to the bug. They were lost to the bug wearing a
// caller's-fault label: every seller was told HTTP 400 invalid_request, which
// is this handler's answer to a malformed request, so every investigation
// started at the seller's own server. A 5xx would have pointed here on day one
// - and, because the plugin only falls back off a hostname that fails, it would
// also have kept sellers connecting on the route that worked the whole time.

check( 'a lost database handle is answered as ours', false !== strpos( $relay_body, "relay_json_out( array( 'error' => 'service_unavailable' ), 503 );" ), true );
check( '  and is no longer folded in with a bad request', false === strpos( $relay_body, '|| ! $db ) {' ), true );
check( '  and says so in the log', false !== strpos( $relay_body, "mad_log( 'Token claim refused: no database handle." ), true );

// Reachable is not working. This probe answered perfectly all six days, from
// constants alone, on a hostname where no seller could connect.
check( 'the reachability probe reports the storage too', false !== strpos( $relay_body, "'. Storage: ' . ( \$db ? 'ready' : 'unavailable' )" ), true );

$oauth_body = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-tcgiant-sync-oauth.php' );

check( '  and the connection test acts on what it says', false !== strpos( $oauth_body, "false === stripos( \$raw, 'Storage: ready' )" ), true );

echo "\nA STRANGER CANNOT WRITE OUR LOG\n" . str_repeat( '=', 112 ) . "\n";

// The deletion challenge answers anyone - it has to, since eBay proves nothing
// before it challenges us - and it now does so on the hostname our host exempts
// from its bot protection. It used to append the caller's raw input, newlines
// and all, to the file we read to work out what happened.
check( 'the challenge log is reduced to safe characters', false !== strpos( $relay_body, "\$logged = preg_replace(" ), true );
check( '  and capped in length', false !== strpos( $relay_body, "\$logged = substr( (string) \$logged, 0, 64 );" ), true );
check( '  so the raw code no longer reaches the file', false === strpos( $relay_body, 'Challenge received. Code: $code' ), true );

// The hash is a different matter: eBay compares it against its own, so the code
// must reach it exactly as it arrived.
check( 'the hash still uses the code untouched', false !== strpos( $relay_body, "\$hash = hash( 'sha256', \$code . EBAY_VERIF_TOKEN . \$endpoint );" ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
