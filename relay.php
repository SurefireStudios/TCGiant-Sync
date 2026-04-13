<?php
/**
 * TCGiant eBay Sync Relay
 *
 * Handles centralized OAuth and Marketplace Account Deletion relaying.
 * Host this on: tcgiant.com/syncconnect/index.php
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

// Buffer output so PHP warnings can't break Location headers.
ob_start();

// --- CONFIGURATION ---
// IMPORTANT: Fill these in from your eBay Developer Portal
define( 'EBAY_APP_ID', 'YOUR_APP_ID' );
define( 'EBAY_CERT_ID', 'YOUR_CERT_ID' );
define( 'EBAY_RU_NAME', 'Stephen_Fitzger-StephenF-TCGian-oahtsra' ); 
define( 'EBAY_VERIF_TOKEN', 'TCGiantVerificationToken1234567890' ); 

// This secret must match what is defined in your plugin code.
define( 'RELAY_SECRET', 'tcgiant_relay_secret_key_500' ); 

// --- DB SETUP ---
// We use SQLite for production-ready, atomic storage of connected sites.
$db = new SQLite3( __DIR__ . '/sync.db' );
$db->busyTimeout( 5000 ); // Wait up to 5 seconds if DB is locked by another process.
$db->exec( "CREATE TABLE IF NOT EXISTS sites (id INTEGER PRIMARY KEY, site_url TEXT UNIQUE, last_connected DATETIME)" );

// --- ROUTING ---
if ( isset( $_GET['challenge_code'] ) ) {
	handle_ebay_challenge();
} elseif ( isset( $_GET['debug_challenge'] ) ) {
	die( "Relay is active. Endpoint target: https://tcgiant.com/syncconnect/relay.php" );
} elseif ( isset( $_GET['code'] ) || isset( $_GET['state'] ) ) {
	handle_ebay_callback();
} elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['action'] ) && 'refresh' === $_POST['action'] ) {
	handle_token_refresh();
} elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	handle_ebay_mad();
} else {
	handle_init_auth();
}

/**
 * Step 1: Handle eBay Marketplace Account Deletion Challenge (GET).
 */
function handle_ebay_challenge() {
	$code = $_GET['challenge_code'];
	// IMPORTANT: This URL must EXACTLY match what is entered in the eBay Developers portal.
	// No trailing space, same protocol (https).
	$endpoint = "https://tcgiant.com/syncconnect/relay.php"; 
	
	$hash = hash( 'sha256', $code . EBAY_VERIF_TOKEN . $endpoint );
	
	// Log the attempt for debugging
	file_put_contents( __DIR__ . '/log.txt', date('Y-m-d H:i:s') . " - Challenge received. Code: $code, Response: $hash\n", FILE_APPEND );

	header( 'Content-Type: application/json' );
	echo json_encode( array( 'challengeResponse' => $hash ) );
	exit;
}

/**
 * Step 2: Handle initial auth request from a WordPress site.
 */
function handle_init_auth() {
	$site_url = $_GET['site_url'] ?? '';
	if ( empty( $site_url ) ) {
		die( 'Error: Missing site_url parameter.' );
	}

	// We pass the origin site URL in the 'state' parameter to eBay.
	// Base64 encoding just the URL prevents WAFs from mistaking it for a JWT (which json_encode does).
	$state = base64_encode( $site_url );
	$scopes = 'https://api.ebay.com/oauth/api_scope https://api.ebay.com/oauth/api_scope/sell.inventory https://api.ebay.com/oauth/api_scope/sell.fulfillment';

	$url = "https://auth.ebay.com/oauth2/authorize" .
		"?client_id=" . EBAY_APP_ID .
		"&redirect_uri=" . EBAY_RU_NAME .
		"&response_type=code" .
		"&scope=" . urlencode( $scopes ) .
		"&state=" . urlencode( $state );

	header( "Location: $url" );
	exit;
}

/**
 * Step 2: Handle callback from eBay after user signs in.
 */
function handle_ebay_callback() {
	global $db;
	$code = $_GET['code'] ?? '';
	$state_raw = $_GET['state'] ?? '';
	
	// Decode the simple base64 string
	$site_url = base64_decode( $state_raw );

	if ( empty( $code ) || empty( $site_url ) || ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
		die( 'Error: Invalid callback data or state. Debug -> Code: ' . (empty($code)?'NO':'YES') . ' | State: ' . htmlspecialchars($state_raw) . ' | Parsed URL: ' . htmlspecialchars($site_url) );
	}

	// Exchange Authorization Code for Tokens server-side.
	$auth_header = base64_encode( EBAY_APP_ID . ':' . EBAY_CERT_ID );
	$ch = curl_init( 'https://api.ebay.com/identity/v1/oauth2/token' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
		"Content-Type: application/x-www-form-urlencoded",
		"Authorization: Basic $auth_header"
	) );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( array(
		'grant_type'   => 'authorization_code',
		'code'         => $code,
		'redirect_uri' => EBAY_RU_NAME
	) ) );

	$response = curl_exec( $ch );
	$data = json_decode( $response, true );

	if ( isset( $data['access_token'] ) ) {
		// Record the site in our relay database (non-critical - proceed even if DB fails).
		try {
			$stmt = $db->prepare( "INSERT OR REPLACE INTO sites (site_url, last_connected) VALUES (:url, :time)" );
			$stmt->bindValue( ':url', $site_url, SQLITE3_TEXT );
			$stmt->bindValue( ':time', date( 'Y-m-d H:i:s' ), SQLITE3_TEXT );
			$stmt->execute();
		} catch ( Exception $e ) {
			// Log but don't block the auth redirect.
			file_put_contents( __DIR__ . '/log.txt', date('Y-m-d H:i:s') . " - DB write failed: " . $e->getMessage() . "\n", FILE_APPEND );
		}

		// Redirect back to the WordPress admin with the tokens.
		$return_url = rtrim( $site_url, '/' ) . '/wp-admin/admin.php?page=tcgiant-sync' .
			'&ebay_access_token=' . urlencode( $data['access_token'] ) .
			'&ebay_refresh_token=' . urlencode( $data['refresh_token'] ) .
			'&ebay_expires_in=' . urlencode( $data['expires_in'] );

		ob_end_clean(); // Flush buffer before redirect.
		header( "Location: $return_url" );
		exit;
	}

	die( 'Authentication Error from eBay: ' . $response );
}

/**
 * Step 3: Handle Token Refresh via Relay.
 */
function handle_token_refresh() {
	$refresh_token = $_POST['refresh_token'] ?? '';
	if ( empty( $refresh_token ) ) {
		header( 'HTTP/1.1 400 Bad Request' );
		echo json_encode( array( 'error' => 'Missing refresh token' ) );
		exit;
	}

	$auth_header = base64_encode( EBAY_APP_ID . ':' . EBAY_CERT_ID );
	$ch = curl_init( 'https://api.ebay.com/identity/v1/oauth2/token' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
		"Content-Type: application/x-www-form-urlencoded",
		"Authorization: Basic $auth_header"
	) );
	
	$scopes = 'https://api.ebay.com/oauth/api_scope https://api.ebay.com/oauth/api_scope/sell.inventory https://api.ebay.com/oauth/api_scope/sell.fulfillment';
	curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh_token,
		'scope'         => $scopes
	) ) );

	$response = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	
	header( "HTTP/1.1 $http_code" );
	header( 'Content-Type: application/json' );
	echo $response;
	exit;
}

/**
 * Handle eBay Marketplace Account Deletion Notification (POST).
 */
function handle_ebay_mad() {
	global $db;
	$payload = file_get_contents( 'php://input' );
	$data = json_decode( $payload, true );

	if ( ! isset( $data['metadata']['topic'] ) || $data['metadata']['topic'] !== 'MARKETPLACE_ACCOUNT_DELETION' ) {
		return;
	}

	// 1. Acknowledge eBay immediately with 200 OK.
	header( 'HTTP/1.1 200 OK' );
	echo json_encode( array( 'status' => 'acknowledged' ) );

	// 2. Relay the deletion notice to all subscribed WordPress sites.
	$results = $db->query( "SELECT site_url FROM sites" );
	while ( $row = $results->fetchArray( SQLITE3_ASSOC ) ) {
		$site_url = $row['site_url'];
		$endpoint = rtrim( $site_url, '/' ) . '/wp-json/tcgiant-sync/v1/ebay-deletion';
		
		// Sign the relay request so the plugin knows it's from YOU.
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $payload . $timestamp, RELAY_SECRET );

		$ch = curl_init( $endpoint );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 ); // Fast timeout to keep relay moving.
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			"Content-Type: application/json",
			"X-TCGiant-Signature: $signature",
			"X-TCGiant-Timestamp: $timestamp"
		) );
		curl_exec( $ch );
	}
	exit;
}
