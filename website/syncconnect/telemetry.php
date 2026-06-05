<?php
/**
 * TCGiant eBay Sync Telemetry Endpoint
 * Receives sync metrics from connected sites.
 */

// Buffer output
ob_start();

$db = new SQLite3( __DIR__ . '/sync.db' );
$db->busyTimeout( 5000 );

// Ensure table has the new columns
@$db->exec( "ALTER TABLE sites ADD COLUMN total_synced INTEGER DEFAULT 0" );
@$db->exec( "ALTER TABLE sites ADD COLUMN license_type TEXT DEFAULT 'free'" );
@$db->exec( "ALTER TABLE sites ADD COLUMN total_pushed INTEGER DEFAULT 0" );
@$db->exec( "ALTER TABLE sites ADD COLUMN total_pulled INTEGER DEFAULT 0" );

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	header("HTTP/1.1 405 Method Not Allowed");
	exit;
}

$payload = file_get_contents( 'php://input' );
$data = json_decode( $payload, true );

// Fallback to $_POST if json_decode fails
if ( ! is_array( $data ) ) {
	$data = $_POST;
}

if ( ! isset( $data['site_url'] ) ) {
	header("HTTP/1.1 400 Bad Request");
	exit;
}

// Allow if ANY of the counts are set
if ( ! isset( $data['synced_count'] ) && ! isset( $data['synced_total'] ) && ! isset( $data['pushed_total'] ) && ! isset( $data['pushed_count'] ) ) {
	header("HTTP/1.1 400 Bad Request");
	exit;
}

$site_url     = rtrim(filter_var($data['site_url'], FILTER_SANITIZE_URL), '/');
$synced_count = isset($data['synced_count']) ? (int) $data['synced_count'] : 0;
$synced_total = isset($data['synced_total']) ? (int) $data['synced_total'] : -1;
$pushed_total = isset($data['pushed_total']) ? (int) $data['pushed_total'] : -1;
$pulled_total = isset($data['pulled_total']) ? (int) $data['pulled_total'] : -1;
$pushed_count = isset($data['pushed_count']) ? (int) $data['pushed_count'] : 0;
$license_type = isset($data['license_type']) ? sanitize_text_field($data['license_type']) : 'free';

// If synced_total is provided (cron ping)
if ( $synced_total >= 0 || $pushed_total >= 0 || $pulled_total >= 0 ) {
	$synced_val = max(0, $synced_total);
	$pushed_val = max(0, $pushed_total);
	$pulled_val = max(0, $pulled_total);

	$stmt = $db->prepare( "UPDATE sites SET total_synced = :total, total_pushed = :pushed, total_pulled = :pulled, last_connected = :time, license_type = :license WHERE site_url = :url OR site_url = :url_with_slash" );
	$stmt->bindValue( ':total', $synced_val, SQLITE3_INTEGER );
	$stmt->bindValue( ':pushed', $pushed_val, SQLITE3_INTEGER );
	$stmt->bindValue( ':pulled', $pulled_val, SQLITE3_INTEGER );
	$stmt->bindValue( ':time', date( 'Y-m-d H:i:s' ), SQLITE3_TEXT );
	$stmt->bindValue( ':license', $license_type, SQLITE3_TEXT );
	$stmt->bindValue( ':url', $site_url, SQLITE3_TEXT );
	$stmt->bindValue( ':url_with_slash', $site_url . '/', SQLITE3_TEXT );
	$stmt->execute();
} elseif ( $synced_count > 0 || $pushed_count > 0 || !empty($license_type) ) {
	// Update the db for an incremental push
	$stmt = $db->prepare( "UPDATE sites SET total_synced = COALESCE(total_synced, 0) + :count, total_pushed = COALESCE(total_pushed, 0) + :pcount, last_connected = :time, license_type = :license WHERE site_url = :url OR site_url = :url_with_slash" );
	$stmt->bindValue( ':count', $synced_count, SQLITE3_INTEGER );
	$stmt->bindValue( ':pcount', $pushed_count, SQLITE3_INTEGER );
	$stmt->bindValue( ':time', date( 'Y-m-d H:i:s' ), SQLITE3_TEXT );
	$stmt->bindValue( ':license', $license_type, SQLITE3_TEXT );
	$stmt->bindValue( ':url', $site_url, SQLITE3_TEXT );
	$stmt->bindValue( ':url_with_slash', $site_url . '/', SQLITE3_TEXT );
	$stmt->execute();
}

header("HTTP/1.1 200 OK");
header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit;
