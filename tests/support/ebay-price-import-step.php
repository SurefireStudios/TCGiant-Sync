<?php
/**
 * One import, for test-ebay-price.php's push-and-import rounds (3.29.0): a
 * listing imported through the real TCGiant_Sync_Mapper onto a product as
 * the push half left it, and what the product then holds printed as JSON.
 * A process of its own: the mapper's WooCommerce is not the exporter's.
 *
 * usage: php tests/support/ebay-price-import-step.php <state.json>
 *   state: settings, product (id and meta, or null for a new one), item
 *   (the listing's Item XML).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/ebay-price-import-world.php';

$state    = json_decode( (string) file_get_contents( (string) ( $argv[1] ?? '' ) ), true );
$SETTINGS = array_merge( array( 'import_auctions' => '1' ), (array) ( $state['settings'] ?? array() ) );

if ( ! empty( $state['product'] ) ) {
	$id                 = (int) $state['product']['id'];
	$POSTS[ $id ]       = array( 'type' => 'product', 'status' => 'publish', 'title' => 'Pushed', 'post_date_gmt' => '2026-01-01 09:00:00' );
	$META[ $id ]        = (array) $state['product']['meta'];
	$NEXT_ID            = max( $NEXT_ID, $id );
}

$saved = import( ebay_item( (string) $state['item'] ) );

echo json_encode( array( 'id' => $saved, 'meta' => $META[ $saved ] ?? array(), 'log' => $LOG ) );
