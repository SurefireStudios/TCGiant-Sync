<?php
/**
 * Harness: the dashboard's PHP2Shell line (3.28.4).
 *
 * PHP2Shell (CVE-2026-109618, CVE-2026-109619) is a flaw in PHP itself, not
 * in this plugin. It is fixed in PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12, and in
 * 8.6.0 from RC4; 8.1 and older get no official fix, only whatever a host
 * backports. The health panel on the TCGiant dashboard - where "Website stock
 * to eBay: On" is - says so when the site's PHP version comes before the fix,
 * and asks the shop to check with its host, since a host can add the fix
 * without changing the number. Nothing is stored, nothing is sent, and there
 * is no notice anywhere else.
 *
 * Loaded for real: TCGiant_Sync_Environment, which holds the table and the
 * judgement. The panel itself, through TCGiant_Sync_Admin::get_system_health(),
 * is in test-push-switch.php's world.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-78s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

define( 'ABSPATH', __DIR__ . '/' );
function __( $text ) { return $text; }

$ROOT = dirname( __DIR__ );
require $ROOT . '/includes/class-tcgiant-sync-environment.php';

echo "\nWHICH VERSIONS COME BEFORE THE FIX\n" . str_repeat( '=', 112 ) . "\n";

foreach ( array(
	// Each supported branch, both sides of its fix.
	'8.2.34'  => 'unfixed',
	'8.2.35'  => '',
	'8.3.20'  => 'unfixed',
	'8.3.35'  => 'unfixed',
	'8.3.36'  => '',
	'8.3.37'  => '',
	'8.4.26'  => 'unfixed',
	'8.4.27'  => '',
	'8.5.11'  => 'unfixed',
	'8.5.12'  => '',
	'8.5.0'   => 'unfixed',
	// No official fix at all.
	'8.1.34'  => 'unsupported',
	'8.1.0'   => 'unsupported',
	'8.0.30'  => 'unsupported',
	'7.4.33'  => 'unsupported',
	'5.6.40'  => 'unsupported',
	// 8.6.0's pre-releases: fixed from RC4.
	'8.6.0RC3'    => 'unfixed',
	'8.6.0rc3'    => 'unfixed',
	'8.6.0RC4'    => '',
	'8.6.0RC10'   => '',
	'8.6.0'       => '',
	'8.6.0-dev'   => 'unfixed',
	'8.6.0alpha1' => 'unfixed',
	'8.6.0beta3'  => 'unfixed',
	'8.6.1'       => '',
	'8.6.1-dev'   => '',
	// Later branches.
	'8.7.0'   => '',
	'9.0.0'   => '',
	// A distribution's build is the release it is a build of.
	'8.3.36-1+ubuntu24.04.1+deb.sury.org+1' => '',
	'8.3.20-1ubuntu2.1'                     => 'unfixed',
	'8.1.2-1ubuntu2.14'                     => 'unsupported',
	// A pre-release of a fixed release comes before it.
	'8.3.36RC1' => 'unfixed',
	'8.4.27-dev' => 'unfixed',
	// Not a version: nothing said.
	''        => '',
	'eight'   => '',
	'8.3'     => '',
) as $version => $want ) {
	check( sprintf( 'PHP %s', '' === (string) $version ? "''" : $version ), TCGiant_Sync_Environment::php2shell( (string) $version ), $want );
}

echo "\nWHAT THE PANEL SAYS\n" . str_repeat( '=', 112 ) . "\n";

$row = TCGiant_Sync_Environment::php2shell_row( '8.3.20' );
check( 'a version before its fix: a warning line', array( $row['label'] ?? null, $row['status'] ?? null, $row['text'] ?? null ), array( 'PHP security', 'warning', 'Check with your host' ) );
check( '  that says which version, what the flaw is, and which versions have the fix', $row['detail'] ?? null, 'PHP 8.3.20 may be affected by PHP2Shell (CVE-2026-109618, CVE-2026-109619), a security flaw in PHP itself. Ask your host whether it has the fix: PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 do. Some hosts add the fix without changing the version number.' );
$row = TCGiant_Sync_Environment::php2shell_row( '8.1.27' );
check( '8.1: PHP no longer updates it, so only the host can have added the fix', $row['detail'] ?? null, 'PHP 8.1.27 may be affected by PHP2Shell (CVE-2026-109618, CVE-2026-109619), a security flaw in PHP itself. PHP no longer updates 8.1, so only your host can have added the fix: ask your host whether it has. PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 have the fix.' );
$row = TCGiant_Sync_Environment::php2shell_row( '7.4.33' );
check( '7.4: the same, for 7.4', false !== strpos( (string) ( $row['detail'] ?? '' ), 'PHP 7.4.33 may be affected' ) && false !== strpos( (string) ( $row['detail'] ?? '' ), 'PHP no longer updates 7.4,' ), true );
$row = TCGiant_Sync_Environment::php2shell_row( '8.6.0RC3' );
check( '8.6.0RC3: the line, as for a branch before its fix', false !== strpos( (string) ( $row['detail'] ?? '' ), 'PHP 8.6.0RC3 may be affected' ) && false !== strpos( (string) ( $row['detail'] ?? '' ), 'Ask your host' ), true );
check( 'a fixed version: no line', array( TCGiant_Sync_Environment::php2shell_row( '8.3.36' ), TCGiant_Sync_Environment::php2shell_row( '8.6.0RC4' ), TCGiant_Sync_Environment::php2shell_row( '8.6.0' ), TCGiant_Sync_Environment::php2shell_row( '9.0.0' ) ), array( null, null, null, null ) );
check( 'a version it cannot read: no line', TCGiant_Sync_Environment::php2shell_row( 'eight' ), null );

echo "\nTHIS SITE'S PHP\n" . str_repeat( '=', 112 ) . "\n";

check( 'given nothing, it judges PHP_VERSION', array( TCGiant_Sync_Environment::php2shell(), TCGiant_Sync_Environment::php2shell_row() ), array( TCGiant_Sync_Environment::php2shell( PHP_VERSION ), TCGiant_Sync_Environment::php2shell_row( PHP_VERSION ) ) );
TCGiant_Sync_Environment::$php_version = '8.4.26';
check( '  or the version a test sets', array( TCGiant_Sync_Environment::php2shell(), TCGiant_Sync_Environment::php2shell_row()['detail'] ?? null ), array( 'unfixed', TCGiant_Sync_Environment::php2shell_row( '8.4.26' )['detail'] ) );
TCGiant_Sync_Environment::$php_version = '8.4.27';
check( '  8.4.27: nothing', TCGiant_Sync_Environment::php2shell_row(), null );
TCGiant_Sync_Environment::$php_version = null;

echo "\nTHE PANEL, AS THE DASHBOARD DRAWS IT\n" . str_repeat( '=', 112 ) . "\n";

// The dashboard's own loop over the health rows, run as it stands, with
// WordPress's escaping played as htmlspecialchars().
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
$view = (string) file_get_contents( $ROOT . '/admin/views/dashboard.php' );
$from = (int) strpos( $view, '<?php foreach ( $health as $metric ) : ?>' );
$to   = (int) strpos( $view, '<?php endforeach; ?>', $from ) + strlen( '<?php endforeach; ?>' );
$loop = substr( $view, $from, $to - $from );
/** The panel's rows, drawn. */
function draw_health( array $health, $loop ) {
	ob_start();
	eval( '?>' . $loop ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	return (string) ob_get_clean();
}
check( 'the dashboard\'s loop over the health rows is found', $from > 0 && $to > $from, true );
// A version that is not plain text - still read as 8.3.20, so the line shows.
TCGiant_Sync_Environment::$php_version = '8.3.20<b>x</b>';
$html = draw_health( array( 'php2shell' => TCGiant_Sync_Environment::php2shell_row() ), $loop );
TCGiant_Sync_Environment::$php_version = null;
check( 'the detail line is drawn under its row', 1 === substr_count( $html, '<p class="health-detail"' ) && strpos( $html, 'class="status-badge warning"' ) < strpos( $html, 'class="health-detail"' ), true );
check( '  escaped: the version is printed as text, never as markup', array( false !== strpos( $html, 'PHP 8.3.20&lt;b&gt;x&lt;/b&gt; may be affected' ), false !== strpos( $html, '<b>x' ) ), array( true, false ) );
$html = draw_health( array( 'stock_push' => array( 'label' => 'Website stock to eBay', 'status' => 'active', 'text' => 'On' ) ), $loop );
check( 'a row with no detail draws no detail line', array( false !== strpos( $html, 'Website stock to eBay' ), false !== strpos( $html, 'health-detail' ) ), array( true, false ) );

echo "\nTHE CODE\n" . str_repeat( '=', 112 ) . "\n";

$env  = (string) file_get_contents( $ROOT . '/includes/class-tcgiant-sync-environment.php' );
$tail = substr( $env, (int) strpos( $env, 'const PHP2SHELL_FIXED' ) );
check( 'the fixed releases are written in one place, the table', array( substr_count( $env, "'8.2.35'" ), substr_count( $env, "'8.3.36'" ), substr_count( $env, "'8.4.27'" ), substr_count( $env, "'8.5.12'" ), substr_count( $env, "'8.6.0RC4'" ) ), array( 1, 1, 1, 1, 1 ) );
check( '  and the table is all of them', TCGiant_Sync_Environment::PHP2SHELL_FIXED, array( '8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12', '8.6' => '8.6.0RC4' ) );
check( 'it stores nothing, sends nothing, and adds no notice', preg_match( '/update_option|add_option|set_transient|wp_remote_|add_action|admin_notices|update_user_meta/', $tail ), 0 );

$admin = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
check( 'the admin asks it once, for the health panel', array( substr_count( $admin, 'php2shell' ) > 0, substr_count( $admin, 'TCGiant_Sync_Environment::php2shell_row()' ) ), array( true, 1 ) );
$health = substr( $admin, (int) strpos( $admin, 'public function get_system_health()' ) );
$health = substr( $health, 0, (int) strpos( $health, 'public function get_sync_stats()' ) );
check( '  inside get_system_health(), and nowhere else', substr_count( $health, 'php2shell' ), substr_count( $admin, 'php2shell' ) );
$view = (string) file_get_contents( $ROOT . '/admin/views/dashboard.php' );
check( 'the dashboard shows the line\'s detail under it, escaped', false !== strpos( $view, "<?php echo esc_html( \$metric['detail'] ); ?>" ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
