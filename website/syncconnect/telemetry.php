<?php
/**
 * TCGiant eBay Sync Telemetry Endpoint
 * Receives sync metrics from connected sites.
 */

// Buffer output
ob_start();

$db = new SQLite3( __DIR__ . '/sync.db' );
$db->busyTimeout( 5000 );

// Ensure table has the new column
@$db->exec( "ALTER TABLE sites ADD COLUMN total_synced INTEGER DEFAULT 0" );
@$db->exec( "ALTER TABLE sites ADD COLUMN license_type TEXT DEFAULT 'free'" );

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
	header("HTTP/1.1 405 Method Not Allowed");
	exit;
}

$payload = file_get_contents( 'php://input' );
$data = json_decode( $payload, true );

if ( ! isset( $data['site_url'] ) || ! isset( $data['synced_count'] ) ) {
	header("HTTP/1.1 400 Bad Request");
	exit;
}

$site_url = rtrim(filter_var($data['site_url'], FILTER_SANITIZE_URL), '/');
$synced_count = isset($data['synced_count']) ? (int) $data['synced_count'] : 0;
$synced_total = isset($data['synced_total']) ? (int) $data['synced_total'] : -1;
$license_type = isset($data['license_type']) ? sanitize_text_field($data['license_type']) : 'free';

if ( $synced_total >= 0 ) {
	$stmt = $db->prepare( "UPDATE sites SET total_synced = :total, last_connected = :time, license_type = :license WHERE site_url = :url OR site_url = :url_with_slash" );
	$stmt->bindValue( ':total', $synced_total, SQLITE3_INTEGER );
	$stmt->bindValue( ':time', date( 'Y-m-d H:i:s' ), SQLITE3_TEXT );
	$stmt->bindValue( ':license', $license_type, SQLITE3_TEXT );
	$stmt->bindValue( ':url', $site_url, SQLITE3_TEXT );
	$stmt->bindValue( ':url_with_slash', $site_url . '/', SQLITE3_TEXT );
	$stmt->execute();
} elseif ( $synced_count > 0 || !empty($license_type) ) {
	// Update the db
	$stmt = $db->prepare( "UPDATE sites SET total_synced = COALESCE(total_synced, 0) + :count, last_connected = :time, license_type = :license WHERE site_url = :url OR site_url = :url_with_slash" );
	$stmt->bindValue( ':count', $synced_count, SQLITE3_INTEGER );
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
