<?php
/**
 * Harness: moving a listing between Fixed Price and Auction.
 *
 * A coin seller had ten Good 'Til Cancelled listings and wanted them as ten-day
 * auctions. Pushing the change produced errors and nothing else. Ending each
 * listing by hand and pushing again produced errors. The bulk format action,
 * then a push, produced errors. They gave up and republished them as GTC.
 *
 * The cause was one line out of order. The choice between eBay's fixed-price
 * calls and its auction calls was made once, from the listing that already
 * existed - and when the push fell through to creating a NEW listing, that
 * choice was never revisited. So the replacement for a dead fixed-price listing
 * was always sent through AddFixedPriceItem, however plainly the seller had
 * asked for an auction.
 *
 * Asserted here:
 *   1. The relist uses the format the seller chose, not the dead listing's. The
 *      pre-fix logic runs alongside and must get it wrong, so the test has teeth.
 *   2. A format change aimed at a listing that is still live is refused here,
 *      with instructions, rather than at eBay without any.
 *   3. That refusal does not touch the ordinary pushes it must not touch.
 *   4. The format created is recorded, so the next revise picks the right call.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-62s got %-30s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// ---- WordPress and WooCommerce, stubbed ------------------------------------------

function __( $text, $domain = null ) { return $text; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

class TCGiant_Sync_Catalog {
	const LISTING_TYPES = array(
		'FixedPriceItem' => 'Fixed Price',
		'Chinese'        => 'Auction',
	);
}

class TCGiant_Sync_Logger {
	public static function log( $message, $level = '' ) {}
	public static function error( $message ) {}
}

class WC_Product {
	private $meta     = array();
	private $variable = false;
	public function __construct( array $meta = array(), $variable = false ) {
		$this->meta     = $meta;
		$this->variable = $variable;
	}
	public function get_id() { return 4242; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function is_type( $type ) { return 'variable' === $type && $this->variable; }
	public function meta_or( $key, $fallback = '(absent)' ) { return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : $fallback; }
}

/** eBay, reduced to which call was made and what it answered. */
class FakeEbay {
	public $calls  = array();
	public $revise = 'ended';   // 'ended' | 'ok' | 'other'

	public function revise_item( $xml ) { return $this->answer( 'ReviseItem' ); }
	public function revise_fixed_price_item( $xml ) { return $this->answer( 'ReviseFixedPriceItem' ); }
	public function add_item( $xml ) { $this->calls[] = 'AddItem'; return array( 'ItemID' => '111222333' ); }
	public function add_fixed_price_item( $xml ) { $this->calls[] = 'AddFixedPriceItem'; return array( 'ItemID' => '111222333' ); }

	private function answer( $name ) {
		$this->calls[] = $name;
		if ( 'ok' === $this->revise ) {
			return array( 'ItemID' => '999888777' );
		}
		return new WP_Error(
			'ebay_error',
			'ended' === $this->revise
				? 'You are not allowed to revise ended listing.'
				: 'Internal error to the application.'
		);
	}
}

// ---- the plugin's decisions, verbatim ---------------------------------------------

class Push {
	public $api;
	public $legacy = false;   // true reproduces the behaviour before the fix

	public function __construct( FakeEbay $api ) { $this->api = $api; }

	public function uses_fixed_price_calls( WC_Product $product, array $settings = array(), $ebay_item_id = '' ) {
		if ( $product->is_type( 'variable' ) ) {
			return true;
		}
		if ( '' !== (string) $ebay_item_id ) {
			$actual = (string) $product->get_meta( '_ebay_listing_type' );
			if ( '' !== $actual ) {
				return 'FixedPriceItem' === $actual;
			}
		}
		return 'FixedPriceItem' === ( $settings['listing_type'] ?? 'FixedPriceItem' );
	}

	public static function is_ended_listing_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$message  = strtolower( $error->get_error_message() );
		$patterns = array(
			'not allowed to revise ended listing',
			'revise ended listing',
			'listing has ended',
			'ended listing',
			'auction has already been closed',
			'already been closed',
		);
		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $message, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/** The refusal that replaces eBay's own wording for a live format change. */
	public function guard_live_format( WC_Product $product, $ebay_item_id ) {
		if ( empty( $ebay_item_id ) || $product->is_type( 'variable' ) ) {
			return null;
		}
		$live_format   = (string) $product->get_meta( '_ebay_listing_type' );
		$wanted_format = (string) $product->get_meta( '_ebay_export_listing_type' );
		$is_active     = 'Active' === (string) $product->get_meta( '_ebay_listing_status' );

		if ( $is_active && '' !== $live_format && '' !== $wanted_format && $live_format !== $wanted_format ) {
			$labels = TCGiant_Sync_Catalog::LISTING_TYPES;
			return new WP_Error(
				'format_change_needs_new_listing',
				sprintf(
					__( 'eBay cannot change a live listing from %1$s to %2$s - it needs a new listing. End listing %3$s first (TCGiant Sync, Listings, tick the products and choose End Listing), then push again: the new %2$s listing is created for you.', 'tcgiant-sync' ),
					$labels[ $live_format ] ?? $live_format,
					$labels[ $wanted_format ] ?? $wanted_format,
					$ebay_item_id
				)
			);
		}
		return null;
	}

	public function do_push( WC_Product $product, array $settings ) {
		$ebay_item_id = (string) $product->get_meta( '_ebay_item_id' );

		$refusal = $this->guard_live_format( $product, $ebay_item_id );
		if ( $refusal ) {
			return $refusal;
		}

		$previous_item_id = '';
		$fixed_price      = $this->uses_fixed_price_calls( $product, $settings, $ebay_item_id );

		if ( '' !== $ebay_item_id ) {
			$response = $fixed_price
				? $this->api->revise_fixed_price_item( '' )
				: $this->api->revise_item( '' );

			if ( ! is_wp_error( $response ) ) {
				return array( 'item_id' => $response['ItemID'], 'action' => 'updated' );
			}
			if ( ! self::is_ended_listing_error( $response ) ) {
				return $response;
			}

			$previous_item_id = $ebay_item_id;
			$ebay_item_id     = '';

			if ( ! $this->legacy ) {
				$fixed_price = $this->uses_fixed_price_calls( $product, $settings, '' );
			}
		}

		$response = $fixed_price
			? $this->api->add_fixed_price_item( '' )
			: $this->api->add_item( '' );

		return array(
			'item_id'          => $response['ItemID'],
			'action'           => $previous_item_id ? 'relisted' : 'created',
			'previous_item_id' => $previous_item_id,
			'listing_type'     => $fixed_price ? 'FixedPriceItem' : 'Chinese',
		);
	}

	/** The meta writes that follow a successful push. */
	public function apply_result( WC_Product $product, array $result ) {
		$product->update_meta_data( '_ebay_item_id', $result['item_id'] );
		if ( 'updated' !== $result['action'] ) {
			$product->update_meta_data( '_ebay_listing_status', 'Active' );
			$product->delete_meta_data( '_ebay_end_time' );
			if ( ! empty( $result['listing_type'] ) ) {
				$product->update_meta_data( '_ebay_listing_type', $result['listing_type'] );
			}
		}
	}
}

/** A product as it stands after the seller ended the listing and asked for an auction. */
function ended_gtc_wanting_auction() {
	return new WC_Product( array(
		'_ebay_item_id'              => '256000111222',
		'_ebay_listing_type'         => 'FixedPriceItem',
		'_ebay_listing_status'       => 'Ended',
		'_ebay_end_time'             => '2026-09-01T10:00:00.000Z',
		'_ebay_export_listing_type'  => 'Chinese',
	) );
}

function run( WC_Product $product, array $settings, $legacy = false ) {
	$api        = new FakeEbay();
	$push       = new Push( $api );
	$push->legacy = $legacy;
	$result     = $push->do_push( $product, $settings );
	return array( $result, $api->calls, $push );
}

$auction = array( 'listing_type' => 'Chinese', 'listing_duration' => 'Days_10' );
$fixed   = array( 'listing_type' => 'FixedPriceItem', 'listing_duration' => 'GTC' );

echo "\nRELISTING AN ENDED FIXED-PRICE LISTING AS A TEN-DAY AUCTION\n" . str_repeat( '=', 118 ) . "\n";

list( $old_result, $old_calls ) = run( ended_gtc_wanting_auction(), $auction, true );
check( 'before the fix: the replacement went through the fixed-price call', implode( ' -> ', $old_calls ), 'ReviseFixedPriceItem -> AddFixedPriceItem' );
check( 'before the fix: recorded as fixed price, though an auction was asked for', $old_result['listing_type'], 'FixedPriceItem' );

list( $result, $calls ) = run( ended_gtc_wanting_auction(), $auction );
check( 'after the fix: the replacement is created as an auction', implode( ' -> ', $calls ), 'ReviseFixedPriceItem -> AddItem' );
check( '  the push reports a relist', $result['action'], 'relisted' );
check( '  the listing it replaced is remembered', $result['previous_item_id'], '256000111222' );
check( '  the format created is reported back', $result['listing_type'], 'Chinese' );

echo "\nAND THE SAME JOURNEY IN REVERSE - AN ENDED AUCTION BACK TO GTC\n" . str_repeat( '=', 118 ) . "\n";

function ended_auction_wanting_gtc() {
	return new WC_Product( array(
		'_ebay_item_id'             => '256000999888',
		'_ebay_listing_type'        => 'Chinese',
		'_ebay_listing_status'      => 'Ended',
		'_ebay_export_listing_type' => 'FixedPriceItem',
	) );
}

list( $old_result2, $old_calls2 ) = run( ended_auction_wanting_gtc(), $fixed, true );
check( 'before the fix: the replacement went through the auction call', implode( ' -> ', $old_calls2 ), 'ReviseItem -> AddItem' );
list( $result2, $calls2 ) = run( ended_auction_wanting_gtc(), $fixed );
check( 'after the fix: the replacement is created as fixed price', implode( ' -> ', $calls2 ), 'ReviseItem -> AddFixedPriceItem' );
check( '  and is recorded as fixed price', $result2['listing_type'], 'FixedPriceItem' );

echo "\nA FORMAT CHANGE AIMED AT A LISTING THAT IS STILL LIVE\n" . str_repeat( '=', 118 ) . "\n";

$live = new WC_Product( array(
	'_ebay_item_id'             => '256000111222',
	'_ebay_listing_type'        => 'FixedPriceItem',
	'_ebay_listing_status'      => 'Active',
	'_ebay_export_listing_type' => 'Chinese',
) );
list( $refused, $refused_calls ) = run( $live, $auction );
check( 'refused before eBay is contacted', is_wp_error( $refused ) ? $refused->get_error_code() : 'no refusal', 'format_change_needs_new_listing' );
check( '  nothing was sent to eBay', implode( ' -> ', $refused_calls ), '' );
$message = is_wp_error( $refused ) ? $refused->get_error_message() : '';
check( '  the message names both formats', false !== strpos( $message, 'Fixed Price' ) && false !== strpos( $message, 'Auction' ), true );
check( '  and the listing to end, and where to end it', false !== strpos( $message, '256000111222' ) && false !== strpos( $message, 'End Listing' ), true );

echo "\nAND THE PUSHES THAT REFUSAL MUST NOT TOUCH\n" . str_repeat( '=', 118 ) . "\n";

// The one that would have been a regression: a shop whose default is Fixed
// Price, holding auctions imported from eBay, pushing a price change to one.
$imported_auction = new WC_Product( array(
	'_ebay_item_id'        => '256000777666',
	'_ebay_listing_type'   => 'Chinese',
	'_ebay_listing_status' => 'Active',
) );
list( $ok_result, $ok_calls ) = run( $imported_auction, $fixed );
check( 'live auction, shop default is fixed price, no per-product choice', is_wp_error( $ok_result ) ? 'refused' : 'went through', 'went through' );

// The revise matches what the listing actually is - an auction. If eBay then
// says it has ended, the replacement is built to the shop's settings: there is
// no longer a live listing to match, and the setting is what the shop has asked
// new listings to be. Deliberate, and narrower than it sounds - eBay's own
// Relist calls are what the auto-relist scheduler and the bulk Relist action
// use, and those keep the format by definition.
check( '  revised through the auction call', $ok_calls[0], 'ReviseItem' );
check( '  once ended, the replacement follows the shop setting', $ok_calls[1], 'AddFixedPriceItem' );

$chosen_auction = new WC_Product( array(
	'_ebay_item_id'             => '256000777666',
	'_ebay_listing_type'        => 'Chinese',
	'_ebay_listing_status'      => 'Active',
	'_ebay_export_listing_type' => 'Chinese',
) );
list( , $chosen_calls ) = run( $chosen_auction, $auction );
check( '  a seller who chose Auction for the product keeps an auction', implode( ' -> ', $chosen_calls ), 'ReviseItem -> AddItem' );

$same = new WC_Product( array(
	'_ebay_item_id'             => '256000555444',
	'_ebay_listing_type'        => 'FixedPriceItem',
	'_ebay_listing_status'      => 'Active',
	'_ebay_export_listing_type' => 'FixedPriceItem',
) );
list( $same_result ) = run( $same, $fixed );
check( 'a choice that matches the live listing', is_wp_error( $same_result ) ? 'refused' : 'went through', 'went through' );

list( $ended_result ) = run( ended_gtc_wanting_auction(), $auction );
check( 'a listing already ended is never refused', is_wp_error( $ended_result ) ? 'refused' : 'went through', 'went through' );

$fresh = new WC_Product( array( '_ebay_export_listing_type' => 'Chinese' ) );
list( $fresh_result, $fresh_calls ) = run( $fresh, $auction );
check( 'a product not yet listed', implode( ' -> ', $fresh_calls ), 'AddItem' );
check( '  reported as created, not relisted', $fresh_result['action'], 'created' );

$variable = new WC_Product( array(
	'_ebay_item_id'             => '256000333222',
	'_ebay_listing_type'        => 'FixedPriceItem',
	'_ebay_listing_status'      => 'Active',
	'_ebay_export_listing_type' => 'Chinese',
), true );
list( $var_result ) = run( $variable, $auction );
check( 'a variable product, which is fixed-price whatever the setting says', is_wp_error( $var_result ) ? 'refused' : 'went through', 'went through' );

echo "\nA REVISE THAT FAILS FOR ANY OTHER REASON MUST NOT CREATE A SECOND LISTING\n" . str_repeat( '=', 118 ) . "\n";

$api      = new FakeEbay();
$api->revise = 'other';
$push     = new Push( $api );
$outcome  = $push->do_push( new WC_Product( array(
	'_ebay_item_id'        => '256000111222',
	'_ebay_listing_type'   => 'FixedPriceItem',
	'_ebay_listing_status' => 'Active',
) ), $fixed );
check( 'the error is returned as it stands', is_wp_error( $outcome ) ? $outcome->get_error_code() : 'not an error', 'ebay_error' );
check( '  and no listing was created', implode( ' -> ', $api->calls ), 'ReviseFixedPriceItem' );

echo "\nWHICH OF EBAY'S WORDINGS ARE READ AS \"ENDED\"\n" . str_repeat( '=', 118 ) . "\n";

$wordings = array(
	'You are not allowed to revise ended listing.'                  => true,
	'The auction has already been closed.'                          => true,
	'This listing has ended and cannot be revised.'                 => true,
	'Item cannot be revised because it has ended.'                  => false,
	'Internal error to the application.'                            => false,
	'The item specific Type is missing.'                            => false,
);
foreach ( $wordings as $text => $expected ) {
	check( '"' . substr( $text, 0, 46 ) . '"', Push::is_ended_listing_error( new WP_Error( 'e', $text ) ), $expected );
}
echo "  NOTE: the fourth is not recognised. Widening the match risks reading an\n";
echo "  unrelated failure as \"ended\" and creating a duplicate listing, so it is\n";
echo "  left alone until a merchant's log shows eBay actually using that wording.\n";

echo "\nWHAT THE PRODUCT REMEMBERS AFTERWARDS\n" . str_repeat( '=', 118 ) . "\n";

$product = ended_gtc_wanting_auction();
list( $r, $c, $pusher ) = run( $product, $auction );
$pusher->apply_result( $product, $r );
check( 'the new listing id is stored', $product->get_meta( '_ebay_item_id' ), '111222333' );
check( 'the product now reads as an auction', $product->get_meta( '_ebay_listing_type' ), 'Chinese' );
check( 'it is live again', $product->get_meta( '_ebay_listing_status' ), 'Active' );
check( 'the old end time is gone', $product->meta_or( '_ebay_end_time' ), '(absent)' );

// The point of recording it: the next ordinary push must now revise through the
// auction call. Before the fix this product still read as fixed price.
$api2        = new FakeEbay();
$api2->revise = 'ok';
$push2       = new Push( $api2 );
$second      = $push2->do_push( $product, $auction );
check( 'the next push revises through the auction call', implode( ' -> ', $api2->calls ), 'ReviseItem' );
check( '  and reports an update, not a new listing', $second['action'], 'updated' );

$updated_product = new WC_Product( array(
	'_ebay_item_id'        => '256000111222',
	'_ebay_listing_type'   => 'Chinese',
	'_ebay_listing_status' => 'Active',
) );
$api3        = new FakeEbay();
$api3->revise = 'ok';
$push3       = new Push( $api3 );
$push3->apply_result( $updated_product, $push3->do_push( $updated_product, $auction ) );
check( 'an ordinary update leaves the recorded format alone', $updated_product->get_meta( '_ebay_listing_type' ), 'Chinese' );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
