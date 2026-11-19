<?php
/**
 * Harness: WooCommerce's emails to eBay buyers (3.43.0).
 *
 * eBay emails the buyer of an eBay order about the purchase, the payment,
 * the shipping and any refund. Until 3.43.0 WooCommerce sent its own emails
 * for an order imported from eBay as well, to the address eBay gave - and
 * 3.37.0 stopped only the two "Refunded order" ones. "Send WooCommerce's
 * emails to eBay buyers?" (No unless the shop says Yes) now holds back
 * WooCommerce's emails to the customer about an eBay order; emails to the
 * shop, website orders and a send the shop asks for by hand go as before.
 *
 * The world is tests/support/followthrough-world.php's (3.37.0): the real
 * order import, tracking push and follow-through class, eBay behind the
 * HTTP call. WooCommerce's emails are played here as WooCommerce runs them
 * (WC_Emails and each WC_Email class; the build played them from
 * WooCommerce as known, its class list checked against the 10.x stubs; fix
 * round 1 plays them as review 1 read WooCommerce 11.2.2's source - its
 * customer Cancelled order email, its review request and fulfilment
 * emails, its REST order actions (OrderActionsRestController::send_email()
 * firing the email's _notification action itself, no resend hooks, then a
 * note), its REST notes (v1-v3 orders/<id>/notes, v4 order-notes with
 * order_id in the body), and its "Deferred emails", which queue
 * woocommerce_new_customer_note and send it in a later request):
 *
 * - each status email on its woocommerce_order_status_<from>_to_<to>
 *   action, or for Completed woocommerce_order_status_completed: the
 *   email's trigger( $order_id, $order ) sets its object to the order and
 *   its recipient to the order's billing email (an admin email, the
 *   shop's), then sends if is_enabled() && get_recipient();
 * - is_enabled(): apply_filters( 'woocommerce_email_enabled_' . $id,
 *   'yes' === $this->enabled, $this->object, $this );
 * - the customer note on woocommerce_new_customer_note, its trigger( $args )
 *   reading the order from its order_id;
 * - the order screen's "Send order details to customer" and "Resend new
 *   order notification" as WC_Meta_Box_Order_Actions::save() sends them:
 *   woocommerce_before_resend_order_emails, the invoice's trigger (which
 *   asks no filter, being sent by hand; a case also plays a WooCommerce
 *   that did ask) or the new-order email's, then
 *   woocommerce_after_resend_order_email;
 * - the order screen's "Add note" as WooCommerce's admin-ajax action
 *   wp_ajax_woocommerce_add_order_note runs it, with WordPress's
 *   doing_action() answering inside it;
 * - a REST request as WordPress's REST server runs a route's callback
 *   (WP_REST_Server::respond_to_request(), as WordPress has since 4.7; its
 *   file is not in the scratch copy of 7.1.3): rest_request_before_callbacks
 *   ( $response, $handler, $request ), the callback,
 *   rest_request_after_callbacks ( $response, $handler, $request ); the
 *   request a WP_REST_Request with get_route() and get_param() (both in
 *   the WordPress stubs).
 *
 * @package TCGiant_Sync
 */

require __DIR__ . '/support/followthrough-world.php';

$pass = 0;
$fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) {
		$pass++;
		printf( "  PASS  %s\n", $label );
		return;
	}
	$fail++;
	printf( "  FAIL  %s\n        got  %s\n        want %s\n", $label, var_export( $got, true ), var_export( $want, true ) );
}

// --- WordPress's current filter, for doing_action() and current_filter() ----

$wp_current_filter = array();
function current_filter() { return end( $GLOBALS['wp_current_filter'] ); }
function doing_filter( $hook = null ) { return null === $hook ? ! empty( $GLOBALS['wp_current_filter'] ) : in_array( $hook, $GLOBALS['wp_current_filter'], true ); }
function doing_action( $hook = null ) { return doing_filter( $hook ); }
/** A hook run as WordPress runs it: its name on the current-filter stack meanwhile. */
function run_hook( $hook, callable $body ) {
	$GLOBALS['wp_current_filter'][] = $hook;
	try {
		return $body();
	} finally {
		array_pop( $GLOBALS['wp_current_filter'] );
	}
}

// --- WooCommerce's emails --------------------------------------------------------

$MAIL = array(); // What WooCommerce sent: array( email ID, order ID, recipient ).

class WC_Email {
	public $id;
	public $title;
	public $customer_email;
	public $enabled = 'yes';
	public $object  = null;
	public $recipient = '';
	public $customer_note = '';
	public function __construct( $id, $title, $customer ) {
		$this->id             = $id;
		$this->title          = $title;
		$this->customer_email = $customer;
	}
	public function get_title() { return $this->title; }
	public function is_customer_email() { return $this->customer_email; }
	public function is_enabled() { return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $this->enabled, $this->object, $this ); }
	public function get_recipient() { return $this->customer_email ? (string) $this->recipient : 'orders@mytcgshop.com'; }
	public function send() { $GLOBALS['MAIL'][] = array( $this->id, $this->object ? (int) $this->object->get_id() : 0, $this->get_recipient() ); }
	/** A status email's trigger, as WooCommerce's. */
	public function trigger( $order_id, $order = false ) {
		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object    = $order;
			$this->recipient = $order->get_billing_email();
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send();
		}
	}
}
/** WC_Email_Customer_Invoice: sent by hand, its trigger asking no filter (INVOICE_ASKS plays one that does). */
class Harness_Invoice extends WC_Email {
	public function trigger( $order_id, $order = false ) {
		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object    = $order;
			$this->recipient = $order->get_billing_email();
		}
		if ( ( empty( $GLOBALS['INVOICE_ASKS'] ) || $this->is_enabled() ) && $this->get_recipient() ) {
			$this->send();
		}
	}
}
/** WC_Email_Customer_Note: trigger( $args ) (its second parameter only to match this harness's base class). */
$NOTES_SENT = array();
class Harness_Note extends WC_Email {
	public function send() {
		parent::send();
		$GLOBALS['NOTES_SENT'][] = $this->customer_note;
	}
	public function trigger( $args, $unused = false ) {
		$order_id = (int) ( $args['order_id'] ?? 0 );
		if ( $order_id ) {
			$this->object = wc_get_order( $order_id );
			if ( $this->object ) {
				$this->recipient     = $this->object->get_billing_email();
				$this->customer_note = (string) ( $args['customer_note'] ?? '' );
			}
		}
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send();
		}
	}
}
$INVOICE_ASKS = false;

$EMAIL = array(
	'new_order'                 => new WC_Email( 'new_order', 'New order', false ),
	'cancelled_order'           => new WC_Email( 'cancelled_order', 'Cancelled order', false ),
	'failed_order'              => new WC_Email( 'failed_order', 'Failed order', false ),
	'customer_on_hold_order'    => new WC_Email( 'customer_on_hold_order', 'Order on-hold', true ),
	'customer_processing_order' => new WC_Email( 'customer_processing_order', 'Processing order', true ),
	'customer_completed_order'  => new WC_Email( 'customer_completed_order', 'Completed order', true ),
	'customer_failed_order'     => new WC_Email( 'customer_failed_order', 'Failed order', true ),
	'customer_invoice'          => new Harness_Invoice( 'customer_invoice', 'Order details', true ),
	'customer_note'             => new Harness_Note( 'customer_note', 'Customer note', true ),
	// WooCommerce 11.2.2 (review 1): its customer Cancelled order email, and
	// those of its optional features, each setting the order as its object.
	'customer_cancelled_order'     => new WC_Email( 'customer_cancelled_order', 'Cancelled order', true ),
	'customer_review_request'      => new WC_Email( 'customer_review_request', 'Review request', true ),
	'customer_fulfillment_created' => new WC_Email( 'customer_fulfillment_created', 'Fulfillment created', true ),
	'customer_fulfillment_updated' => new WC_Email( 'customer_fulfillment_updated', 'Fulfillment updated', true ),
	'customer_fulfillment_deleted' => new WC_Email( 'customer_fulfillment_deleted', 'Fulfillment deleted', true ),
);
// Each email on the status changes WooCommerce sends it for.
$ON = array(
	'new_order'                 => array( 'pending_to_processing', 'pending_to_completed', 'pending_to_on-hold', 'failed_to_processing', 'failed_to_completed', 'failed_to_on-hold', 'cancelled_to_processing', 'cancelled_to_completed', 'cancelled_to_on-hold' ),
	'customer_processing_order' => array( 'cancelled_to_processing', 'failed_to_processing', 'on-hold_to_processing', 'pending_to_processing' ),
	'customer_on_hold_order'    => array( 'pending_to_on-hold', 'failed_to_on-hold', 'cancelled_to_on-hold' ),
	'cancelled_order'           => array( 'processing_to_cancelled', 'on-hold_to_cancelled' ),
	'customer_cancelled_order'  => array( 'processing_to_cancelled', 'on-hold_to_cancelled' ),
	'failed_order'              => array( 'pending_to_failed', 'on-hold_to_failed' ),
	'customer_failed_order'     => array( 'pending_to_failed', 'on-hold_to_failed' ),
);
add_action( 'woocommerce_order_status_changed', function ( $id, $from, $to, $order ) {
	do_action( 'woocommerce_order_status_' . $from . '_to_' . $to, $id, $order );
}, 10, 4 );
foreach ( $ON as $tcg_id => $tcg_changes ) {
	foreach ( $tcg_changes as $tcg_change ) {
		add_action( 'woocommerce_order_status_' . $tcg_change, function ( $id, $order ) use ( $tcg_id ) { $GLOBALS['EMAIL'][ $tcg_id ]->trigger( $id, $order ); }, 10, 2 );
	}
}
add_action( 'woocommerce_order_status_completed', function ( $id, $order ) { $GLOBALS['EMAIL']['customer_completed_order']->trigger( $id, $order ); }, 10, 2 );
// The customer note, sent at once - or, with WooCommerce's "Deferred
// emails" on (deferred()), queued by WC_Emails::queue_transactional_email(),
// hooked on the action as WooCommerce hooks it, and sent by a later request
// (run_deferred()).
$DEFERRED = false;
$QUEUED   = array();
class WC_Emails {
	public static function queue_transactional_email( ...$args ) { $GLOBALS['QUEUED'][] = $args[0] ?? array(); }
}
add_action( 'woocommerce_new_customer_note', function ( $args ) {
	if ( $GLOBALS['DEFERRED'] ) {
		return;
	}
	$GLOBALS['EMAIL']['customer_note']->trigger( $args );
} );
/** WooCommerce's "Deferred emails" on or off: its queue hooked on the note's action, or not. */
function deferred( $on ) {
	$GLOBALS['DEFERRED'] = (bool) $on;
	$cb = array( 'WC_Emails', 'queue_transactional_email' );
	foreach ( $GLOBALS['FILTERS']['woocommerce_new_customer_note'][10] ?? array() as $k => $f ) {
		if ( $f[0] === $cb ) {
			unset( $GLOBALS['FILTERS']['woocommerce_new_customer_note'][10][ $k ] );
		}
	}
	if ( $on ) {
		add_action( 'woocommerce_new_customer_note', $cb, 10, 10 );
	}
}
/** WordPress's has_action(): with a callback, its priority, or false. */
function has_action( $hook, $callback = false ) {
	foreach ( $GLOBALS['FILTERS'][ $hook ] ?? array() as $prio => $list ) {
		foreach ( $list as $f ) {
			if ( false === $callback ) {
				return true;
			}
			if ( $f[0] === $callback ) {
				return $prio;
			}
		}
	}
	return false;
}
/** Action Scheduler's later request sending what Deferred emails queued. */
function run_deferred() {
	$queued = $GLOBALS['QUEUED'];
	$GLOBALS['QUEUED'] = array();
	foreach ( $queued as $args ) {
		$GLOBALS['EMAIL']['customer_note']->trigger( $args );
	}
}

/** A REST request, as WordPress's REST server runs its route's callback. */
class WP_REST_Request {
	private $route;
	private $params;
	public function __construct( $method, $route, array $params = array() ) { $this->route = $route; $this->params = $params; }
	public function get_route() { return $this->route; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}
function rest_request( $route, array $params, callable $callback ) {
	$request  = new WP_REST_Request( 'POST', $route, $params );
	$response = apply_filters( 'rest_request_before_callbacks', null, array(), $request );
	$response = $callback( $request );
	return apply_filters( 'rest_request_after_callbacks', $response, array(), $request );
}
/** WooCommerce's REST order action send_email (OrderActionsRestController): the email's own _notification, then its note. */
function rest_send_email( $order, $template ) {
	return rest_request( '/wc/v3/orders/' . $order->get_id() . '/actions/send_email', array( 'id' => $order->get_id(), 'template_id' => $template ), function () use ( $order, $template ) {
		$GLOBALS['EMAIL'][ $template ]->trigger( $order->get_id(), $order );
		$order->add_order_note( 'Email template sent.', false, true );
		return true;
	} );
}
/** A note to the customer through WooCommerce's REST notes: v3 orders/<id>/notes, or v4 order-notes. */
function rest_note( $order, $text, $v4 = false ) {
	$route = $v4 ? '/wc/v4/order-notes' : '/wc/v3/orders/' . $order->get_id() . '/notes';
	return rest_request( $route, array( 'order_id' => $order->get_id(), 'note' => $text, 'customer_note' => true ), function () use ( $order, $text ) {
		return $order->add_order_note( $text, 1, true );
	} );
}

/** The order screen's order actions, as WC_Meta_Box_Order_Actions::save() sends them. */
function order_action( $action, $order ) {
	if ( 'send_order_details' === $action ) {
		do_action( 'woocommerce_before_resend_order_emails', $order, 'customer_invoice' );
		$GLOBALS['EMAIL']['customer_invoice']->trigger( $order->get_id(), $order );
		$order->add_order_note( 'Order details manually sent to customer.', false, true );
		do_action( 'woocommerce_after_resend_order_email', $order, 'customer_invoice' );
	} elseif ( 'send_order_details_admin' === $action ) {
		do_action( 'woocommerce_before_resend_order_emails', $order, 'new_order' );
		$GLOBALS['EMAIL']['new_order']->trigger( $order->get_id(), $order );
		do_action( 'woocommerce_after_resend_order_email', $order, 'new_order' );
	}
}
/** The order screen's "Add note", as wp_ajax_woocommerce_add_order_note runs it. */
function ajax_add_note( $order, $text, $customer ) {
	return run_hook( 'wp_ajax_woocommerce_add_order_note', function () use ( $order, $text, $customer ) {
		return $order->add_order_note( $text, $customer ? 1 : 0, true );
	} );
}

// --- Driving it --------------------------------------------------------------------

/** WooCommerce's emails sent, as ID => order IDs, in order. */
function mail_ids() { return array_map( function ( $m ) { return $m[0]; }, $GLOBALS['MAIL'] ); }
/** A shop: as the world's, with WooCommerce's emails reset. */
function email_shop( array $settings = array() ) {
	shop( $settings );
	$GLOBALS['MAIL'] = array();
	$GLOBALS['INVOICE_ASKS'] = false;
	deferred( false );
	$GLOBALS['QUEUED'] = array();
	$GLOBALS['NOTES_SENT'] = array();
	foreach ( $GLOBALS['EMAIL'] as $email ) {
		$email->object = null;
		$email->recipient = '';
		$email->enabled = 'yes';
	}
}
/** An order imported from eBay, made here as the import leaves it, with the buyer's address. */
function ebay_order_here( $status = 'processing', $email = 'buyer@members.ebay.com' ) {
	$o     = ebay( array( 'id' => '27-' . ( 10000 + count( $GLOBALS['EBAY'] ) ) . '-00001' ) );
	$order = stored_order( $o, $status );
	$order->set_billing_email( $email );
	return $order;
}
/** A website order: no eBay meta, its own address. */
function website_order_here( $status = 'processing' ) {
	$order = wc_create_order();
	$order->set_billing_email( 'shopper@example.com' );
	$order->status       = $status;
	$order->saved_status = $status;
	return $order;
}
function held_lines() { return log_count( 'was not sent to the buyer' ); }

$Q   = '"';
$HELD_LINE = function ( $title, $id, $order ) {
	return sprintf( 'WooCommerce\'s "%s" (%s) email for WC Order #%d (eBay order %s) was not sent to the buyer: eBay emails the buyers of eBay orders itself, and "Send WooCommerce\'s emails to eBay buyers?" is No in TCGiant Sync, Settings. Set it to Yes to send them. Emails to your shop still go. This is said once a day; others held back today are not logged.', $title, $id, $order->get_id(), $order->get_meta( '_ebay_order_id' ) );
};

echo "\nTHE SETTING AND THE HOOKS\n" . str_repeat( '=', 124 ) . "\n";

email_shop();
check( 'a new install or an upgrade (never chosen): No', TCGiant_Sync_Order_Followthrough::buyer_emails_on(), false );
check( '  No as stored, and anything but "1": No; "1": Yes', array_map( function ( $v ) { return TCGiant_Sync_Order_Followthrough::buyer_emails_on( array( 'ebay_buyer_emails' => $v ) ); }, array( '0', '', 'yes', '1', 1 ) ), array( false, false, false, true, true ) );
check( '  with order import off too: the orders imported before are still eBay\'s', TCGiant_Sync_Order_Followthrough::buyer_emails_on( array( 'enable_order_import' => 0, 'ebay_buyer_emails' => '1' ) ), true );
$tcg_hooked = array();
foreach ( $FILTERS as $tcg_hook => $tcg_prios ) {
	foreach ( $tcg_prios as $tcg_prio => $tcg_list ) {
		foreach ( $tcg_list as $tcg_cb ) {
			if ( is_array( $tcg_cb[0] ) && 'TCGiant_Sync_Order_Followthrough' === $tcg_cb[0][0] && 0 === strpos( $tcg_hook, 'woocommerce_email_enabled_' ) ) {
				$tcg_hooked[ $tcg_hook ] = $tcg_cb[0][1] . '@' . $tcg_prio . '/' . $tcg_cb[1];
			}
		}
	}
}
ksort( $tcg_hooked );
check( 'filtered: the eleven customer emails (buyer_email), and the two Refunded ones as 3.37.0 (no_refund_email); none to the shop', $tcg_hooked, array(
	'woocommerce_email_enabled_customer_cancelled_order'          => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_completed_order'          => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_failed_order'             => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_fulfillment_created'      => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_fulfillment_deleted'      => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_fulfillment_updated'      => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_invoice'                  => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_note'                     => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_on_hold_order'            => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_partially_refunded_order' => 'no_refund_email@10/2',
	'woocommerce_email_enabled_customer_processing_order'         => 'buyer_email@20/3',
	'woocommerce_email_enabled_customer_refunded_order'           => 'no_refund_email@10/2',
	'woocommerce_email_enabled_customer_review_request'           => 'buyer_email@20/3',
) );
check( '  and the order actions\' two hooks, the REST request\'s two, and the note kept before WooCommerce\'s handler (fix round 1)', array( isset( $FILTERS['woocommerce_before_resend_order_emails'][10] ), isset( $FILTERS['woocommerce_after_resend_order_email'][10] ), isset( $FILTERS['rest_request_before_callbacks'][10] ), isset( $FILTERS['rest_request_after_callbacks'][10] ), isset( $FILTERS['woocommerce_new_customer_note'][5] ) ), array( true, true, true, true, true ) );
check( '  BUYER_EMAILS names them', TCGiant_Sync_Order_Followthrough::BUYER_EMAILS, array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_failed_order', 'customer_cancelled_order', 'customer_invoice', 'customer_note', 'customer_review_request', 'customer_fulfillment_created', 'customer_fulfillment_updated', 'customer_fulfillment_deleted' ) );
// Fix round 1 (review 1's M1): every customer email WooCommerce 11.2.2
// registers, as review 1 read its source (class-wc-emails.php init() and
// the email classes) - a fixed list - each held here, 3.37.0's, or out for
// a reason. The two POS IDs, the account emails' IDs and the partial
// refund's ID are as WooCommerce has long named them (review 1 names the
// POS emails and the Block Email Editor's partial refund class, not their
// IDs; the stubs have the classes).
$WC_11_2_2_CUSTOMER = array(
	'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_failed_order', 'customer_cancelled_order',
	'customer_refunded_order', 'customer_partially_refunded_order', 'customer_invoice', 'customer_note', 'customer_review_request',
	'customer_fulfillment_created', 'customer_fulfillment_updated', 'customer_fulfillment_deleted',
	'customer_pos_completed_order', 'customer_pos_refunded_order', 'customer_new_account', 'customer_reset_password',
	// Review 2's LOW 1: behind 11.2.2's order_withdrawal feature.
	'customer_order_withdrawal_requested',
);
$tcg_out = array(
	'customer_refunded_order'           => '3.37.0 (no_refund_email)',
	'customer_partially_refunded_order' => '3.37.0 (no_refund_email)',
	'customer_pos_completed_order'      => 'a point-of-sale order, never an eBay order',
	'customer_pos_refunded_order'       => 'a point-of-sale order, never an eBay order',
	'customer_new_account'              => 'no order (the import makes no account)',
	'customer_reset_password'           => 'no order',
	// Left alone on purpose (review 2's LOW 1): it confirms a withdrawal the
	// customer asked for, and its object is that request ( (object) $data ),
	// not an order - ebay_order() could never match it.
	'customer_order_withdrawal_requested' => 'the customer\'s own withdrawal request; its object is the request, not an order',
);
$tcg_left = array();
foreach ( $WC_11_2_2_CUSTOMER as $tcg_id ) {
	if ( ! in_array( $tcg_id, TCGiant_Sync_Order_Followthrough::BUYER_EMAILS, true ) && ! isset( $tcg_out[ $tcg_id ] ) ) {
		$tcg_left[] = $tcg_id;
	}
}
check( 'every customer email WooCommerce 11.2.2 registers: held here, 3.37.0\'s, or out for a reason - none left over; nothing held that 11.2.2 does not have', array( $tcg_left, array_values( array_diff( TCGiant_Sync_Order_Followthrough::BUYER_EMAILS, $WC_11_2_2_CUSTOMER ) ) ), array( array(), array() ) );

echo "\nTHE ORDER IMPORT: A PAID ORDER, AND ONE PAID LATER (3.31.0)\n" . str_repeat( '=', 124 ) . "\n";

// Paid as it arrives: pending to Processing in one save.
email_shop();
ebay( array( 'id' => '27-11111-00001', 'email' => 'buyer1@members.ebay.com' ) );
run_import();
$tcg_o = wc_for( '27-11111-00001' );
check( 'an eBay order imported paid, at No: New order to the shop; Processing order to the buyer held back', array( $tcg_o->get_status(), $tcg_o->get_billing_email(), mail_ids() ), array( 'processing', 'buyer1@members.ebay.com', array( 'new_order' ) ) );
check( '  one log line, naming the email and the order, never the address', array( held_lines(), logged( $HELD_LINE( 'Processing order', 'customer_processing_order', $tcg_o ) ), logged( 'buyer1@members.ebay.com' ) ), array( 1, true, false ) );
email_shop( array( 'ebay_buyer_emails' => '1' ) );
ebay( array( 'id' => '27-11112-00001', 'email' => 'buyer2@members.ebay.com' ) );
run_import();
check( '  at Yes: both, as before 3.43.0; nothing logged', array( mail_ids(), $MAIL[1][2] ?? null, held_lines() ), array( array( 'new_order', 'customer_processing_order' ), 'buyer2@members.ebay.com', 0 ) );
email_shop();
ebay( array( 'id' => '27-11113-00001' ) );
run_import();
check( '  eBay gave no address: WooCommerce sends the buyer nothing anyway, and nothing is said', array( mail_ids(), held_lines() ), array( array( 'new_order' ), 0 ) );

// Unpaid as it arrives (Pending payment), paid later: the follow-up.
foreach ( array( '0' => 'No', '1' => 'Yes' ) as $tcg_v => $tcg_word ) {
	email_shop( array( 'ebay_buyer_emails' => (string) $tcg_v ) );
	ebay( array( 'id' => '27-11120-00001', 'status' => 'Active', 'email' => 'buyer3@members.ebay.com' ) );
	run_import();
	$tcg_o = wc_for( '27-11120-00001' );
	$tcg_first = array( $tcg_o->get_status(), mail_ids() );
	$EBAY['27-11120-00001']['status'] = 'Completed';
	$EBAY['27-11120-00001']['mod']    = time() - 60;
	run_import();
	check( 'unpaid on arrival (Pending payment, no email), paid later (3.31.0), at ' . $tcg_word . ': New order to the shop; Processing ' . ( '0' === (string) $tcg_v ? 'held back' : 'sent' ), array( $tcg_first, $tcg_o->get_status(), mail_ids() ), array( array( 'pending', array() ), 'processing', '0' === (string) $tcg_v ? array( 'new_order' ) : array( 'new_order', 'customer_processing_order' ) ) );
}

// A cancellation asked for on eBay: On hold.
email_shop();
ebay( array( 'id' => '27-11130-00001', 'status' => 'CancelPending', 'email' => 'buyer4@members.ebay.com' ) );
run_import();
check( 'a cancellation asked for (On hold) at No: New order to the shop; Order on-hold held back', array( wc_for( '27-11130-00001' )->get_status(), mail_ids() ), array( 'on-hold', array( 'new_order' ) ) );
email_shop( array( 'ebay_buyer_emails' => '1' ) );
ebay( array( 'id' => '27-11131-00001', 'status' => 'CancelPending', 'email' => 'buyer5@members.ebay.com' ) );
run_import();
check( '  at Yes: both', mail_ids(), array( 'new_order', 'customer_on_hold_order' ) );

echo "\nTHE SHOP'S OWN STATUS CHANGES, CANCELLATIONS AND REFUNDS\n" . str_repeat( '=', 124 ) . "\n";

// Marked Completed by the shop (its tracking push, which needs a tracking
// number, is the orders capture's and test-order-followthrough's).
foreach ( array( '0', '1' ) as $tcg_v ) {
	email_shop( array( 'ebay_buyer_emails' => $tcg_v ) );
	$tcg_o = ebay_order_here();
	$tcg_w = website_order_here();
	$tcg_o->update_status( 'completed' );
	$tcg_w->update_status( 'completed' );
	check( 'marked Completed by the shop, at ' . ( '1' === $tcg_v ? 'Yes' : 'No' ) . ': the eBay order\'s Completed order ' . ( '1' === $tcg_v ? 'sent' : 'held back' ) . '; the website order\'s sent', $MAIL, '1' === $tcg_v ? array( array( 'customer_completed_order', $tcg_o->get_id(), 'buyer@members.ebay.com' ), array( 'customer_completed_order', $tcg_w->get_id(), 'shopper@example.com' ) ) : array( array( 'customer_completed_order', $tcg_w->get_id(), 'shopper@example.com' ) ) );
}

// Cancelled: by the shop from Processing or On hold (to the shop only), and
// on eBay while unpaid (WooCommerce sends none for Pending payment).
email_shop();
$tcg_o = ebay_order_here( 'processing' );
$tcg_o->update_status( 'cancelled' );
$tcg_h = ebay_order_here( 'on-hold' );
$tcg_h->update_status( 'cancelled' );
check( 'an eBay order cancelled here, from Processing and from On hold, at No: Cancelled order to the shop, both; WooCommerce 11.2.2\'s customer Cancelled order held back (fix round 1, M1)', mail_ids(), array( 'cancelled_order', 'cancelled_order' ) );
email_shop( array( 'ebay_buyer_emails' => '1' ) );
$tcg_o = ebay_order_here( 'processing' );
$tcg_o->update_status( 'cancelled' );
check( '  at Yes: the customer\'s goes too', mail_ids(), array( 'cancelled_order', 'customer_cancelled_order' ) );
// eBay completes a cancellation the buyer asked for: On hold to Cancelled.
email_shop();
ebay( array( 'id' => '27-11141-00001', 'status' => 'CancelPending', 'email' => 'buyer7@members.ebay.com' ) );
run_import();
$EBAY['27-11141-00001']['status'] = 'Cancelled';
$EBAY['27-11141-00001']['mod']    = time() - 60;
run_import();
check( '  a cancellation eBay completes (the follow-up, On hold to Cancelled), at No: New order and Cancelled order to the shop; the customer\'s On hold and Cancelled held back', array( wc_for( '27-11141-00001' )->get_status(), mail_ids() ), array( 'cancelled', array( 'new_order', 'cancelled_order' ) ) );
email_shop();
ebay( array( 'id' => '27-11140-00001', 'status' => 'Active', 'email' => 'buyer6@members.ebay.com' ) );
run_import();
$EBAY['27-11140-00001']['status'] = 'Cancelled';
$EBAY['27-11140-00001']['mod']    = time() - 60;
run_import();
check( '  cancelled on eBay while unpaid: Cancelled here, and WooCommerce sends nothing for Pending payment to Cancelled', array( wc_for( '27-11140-00001' )->get_status(), mail_ids() ), array( 'cancelled', array() ) );

// Failed, set by hand: the shop's Failed order goes; the customer's held.
email_shop();
$tcg_o = ebay_order_here( 'on-hold' );
$tcg_o->update_status( 'failed' );
check( 'an eBay order set to Failed by hand, at No: Failed order to the shop; the customer\'s held back', mail_ids(), array( 'failed_order' ) );
email_shop( array( 'ebay_buyer_emails' => '1' ) );
$tcg_o = ebay_order_here( 'on-hold' );
$tcg_o->update_status( 'failed' );
check( '  at Yes: both', mail_ids(), array( 'failed_order', 'customer_failed_order' ) );

// A website order through every one of them: never touched.
email_shop();
$tcg_w = website_order_here( 'pending' );
$tcg_w->update_status( 'processing' );
$tcg_w->update_status( 'completed' );
$tcg_w2 = website_order_here( 'pending' );
$tcg_w2->update_status( 'on-hold' );
$tcg_w2->update_status( 'failed' );
$tcg_w2->add_order_note( 'Your parcel is on its way.', 1 );
order_action( 'send_order_details', $tcg_w2 );
$tcg_w3 = website_order_here( 'processing' );
$tcg_w3->update_status( 'cancelled' );
foreach ( array( 'customer_review_request', 'customer_fulfillment_created' ) as $tcg_id ) {
	$EMAIL[ $tcg_id ]->trigger( $tcg_w3->get_id(), $tcg_w3 );
}
check( 'a website order at No: every email WooCommerce sends, as before', mail_ids(), array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'new_order', 'customer_on_hold_order', 'failed_order', 'customer_failed_order', 'customer_note', 'customer_invoice', 'cancelled_order', 'customer_cancelled_order', 'customer_review_request', 'customer_fulfillment_created' ) );
check( '  and nothing logged', held_lines(), 0 );

// Refunds: 3.37.0's rule, whatever this setting says.
foreach ( array( '0', '1' ) as $tcg_v ) {
	email_shop( array( 'ebay_buyer_emails' => $tcg_v ) );
	$tcg_o = ebay_order_here();
	$tcg_o->total = 25.0;
	$tcg_w = website_order_here();
	$tcg_w->total = 25.0;
	wc_create_refund( array( 'order_id' => $tcg_o->get_id(), 'amount' => 5 ) );
	wc_create_refund( array( 'order_id' => $tcg_o->get_id(), 'amount' => 20 ) );
	wc_create_refund( array( 'order_id' => $tcg_w->get_id(), 'amount' => 25 ) );
	check( 'refunds at ' . ( '1' === $tcg_v ? 'Yes' : 'No' ) . ' (3.37.0, pinned): an eBay order\'s partial and full Refunded order emails not sent; a website order\'s sent; nothing logged', array( $EMAILS, held_lines() ), array( array( array( 'customer_refunded_order', $tcg_w->get_id() ) ), 0 ) );
}
check( '  no_refund_email() unchanged: its test is ebay_order()\'s, for any order', array(
	TCGiant_Sync_Order_Followthrough::no_refund_email( true, $tcg_o ), TCGiant_Sync_Order_Followthrough::no_refund_email( true, $tcg_w ), TCGiant_Sync_Order_Followthrough::no_refund_email( false, $tcg_w ), TCGiant_Sync_Order_Followthrough::no_refund_email( true, null ),
	TCGiant_Sync_Order_Followthrough::ebay_order( $tcg_o ), TCGiant_Sync_Order_Followthrough::ebay_order( $tcg_w ), TCGiant_Sync_Order_Followthrough::ebay_order( null ), TCGiant_Sync_Order_Followthrough::ebay_order( 'x' ),
), array( false, true, false, true, true, false, false, false ) );

// WooCommerce 11.2.2's optional features (review 1's M1): the review
// request and the three fulfilment emails, each with the order as its object.
foreach ( array( '0', '1' ) as $tcg_v ) {
	email_shop( array( 'ebay_buyer_emails' => $tcg_v ) );
	$tcg_o = ebay_order_here( 'completed' );
	foreach ( array( 'customer_review_request', 'customer_fulfillment_created', 'customer_fulfillment_updated', 'customer_fulfillment_deleted' ) as $tcg_id ) {
		$EMAIL[ $tcg_id ]->trigger( $tcg_o->get_id(), $tcg_o );
	}
	check( 'the review request and the fulfilment emails for an eBay order, at ' . ( '1' === $tcg_v ? 'Yes: sent' : 'No: held back' ), mail_ids(), '1' === $tcg_v ? array( 'customer_review_request', 'customer_fulfillment_created', 'customer_fulfillment_updated', 'customer_fulfillment_deleted' ) : array() );
}

echo "\nBY HAND: THE ORDER ACTIONS AND A NOTE TO THE CUSTOMER\n" . str_repeat( '=', 124 ) . "\n";

email_shop();
$tcg_o = ebay_order_here();
order_action( 'send_order_details', $tcg_o );
check( '"Send order details to customer" on an eBay order at No: sent (WooCommerce asks no filter for it)', array( $MAIL, held_lines() ), array( array( array( 'customer_invoice', $tcg_o->get_id(), 'buyer@members.ebay.com' ) ), 0 ) );
email_shop();
$INVOICE_ASKS = true;
$tcg_o = ebay_order_here();
order_action( 'send_order_details', $tcg_o );
check( '  a WooCommerce that asked the filter for it: still sent - sent from the order actions, by hand', array( mail_ids(), held_lines() ), array( array( 'customer_invoice' ), 0 ) );
$GLOBALS['EMAIL']['customer_invoice']->trigger( $tcg_o->get_id(), $tcg_o );
check( '  and the same email sent by anything else, after: held back, and said', array( mail_ids(), held_lines() ), array( array( 'customer_invoice' ), 1 ) );
email_shop();
$tcg_o = ebay_order_here();
order_action( 'send_order_details_admin', $tcg_o );
check( '"Resend new order notification": to the shop, as ever', mail_ids(), array( 'new_order' ) );
$tcg_o->update_status( 'completed' );
check( '  the order actions over: the Completed email after them is held back again', array( mail_ids(), held_lines() ), array( array( 'new_order' ), 1 ) );
order_action( 'send_order_details', ebay_order_here() );
check( '  the by-hand mark is per order: another order\'s by-hand send leaves this one as it was', count( $MAIL ), 2 );

// A note to the customer, from the order screen (admin-ajax).
email_shop();
$tcg_o = ebay_order_here();
ajax_add_note( $tcg_o, 'Shipped today, thank you.', true );
check( 'a "Note to customer" added on the order screen: sent, by hand', array( $MAIL, held_lines() ), array( array( array( 'customer_note', $tcg_o->get_id(), 'buyer@members.ebay.com' ) ), 0 ) );
ajax_add_note( $tcg_o, 'Private: packed by Sam.', false );
check( '  a private note: no email, as ever', count( $MAIL ), 1 );
$tcg_o->add_order_note( 'Your tracking number is 1Z999.', 1 );
check( '  a customer note added by anything else (another plugin, a cron): held back at No', array( count( $MAIL ), held_lines() ), array( 1, 1 ) );
email_shop( array( 'ebay_buyer_emails' => '1' ) );
$tcg_o = ebay_order_here();
$tcg_o->add_order_note( 'Your tracking number is 1Z999.', 1 );
check( '  at Yes: sent', mail_ids(), array( 'customer_note' ) );

echo "\nTHE FILTER ITSELF\n" . str_repeat( '=', 124 ) . "\n";

email_shop();
$tcg_o = ebay_order_here();
$tcg_w = website_order_here();
$tcg_e = $EMAIL['customer_processing_order'];
check( 'an email WooCommerce has switched off stays off; an eBay order\'s held; a website order\'s, a missing order, an order with no address left as they came', array(
	TCGiant_Sync_Order_Followthrough::buyer_email( false, $tcg_o, $tcg_e ),
	TCGiant_Sync_Order_Followthrough::buyer_email( true, $tcg_o, $tcg_e ),
	TCGiant_Sync_Order_Followthrough::buyer_email( true, $tcg_w, $tcg_e ),
	TCGiant_Sync_Order_Followthrough::buyer_email( true, null, $tcg_e ),
	TCGiant_Sync_Order_Followthrough::buyer_email( true, ebay_order_here( 'processing', '' ), $tcg_e ),
), array( false, false, true, true, true ) );
// No email handed: the ID from the filter running now.
email_shop();
$tcg_o = ebay_order_here();
$tcg_got = run_hook( 'woocommerce_email_enabled_customer_note', function () use ( $tcg_o ) { return TCGiant_Sync_Order_Followthrough::buyer_email( true, $tcg_o ); } );
check( 'no email handed: the ID read from the filter running now, and the line names it', array( $tcg_got, logged( 'WooCommerce\'s customer_note email for WC Order #' . $tcg_o->get_id() . ' (eBay order ' . $tcg_o->get_meta( '_ebay_order_id' ) . ') was not sent to the buyer' ) ), array( false, true ) );
$tcg_got = run_hook( 'wp_ajax_woocommerce_add_order_note', function () use ( $tcg_o ) {
	return run_hook( 'woocommerce_email_enabled_customer_note', function () use ( $tcg_o ) { return TCGiant_Sync_Order_Followthrough::buyer_email( true, $tcg_o ); } );
} );
check( '  inside the order screen\'s Add note: by hand, sent', $tcg_got, true );
$tcg_got = run_hook( 'wp_ajax_woocommerce_add_order_note', function () use ( $tcg_o ) { return TCGiant_Sync_Order_Followthrough::buyer_email( true, $tcg_o, $GLOBALS['EMAIL']['customer_completed_order'] ); } );
check( '  but only a customer note: another email in that request is held back', $tcg_got, false );

echo "\nTHE LOG: ONE LINE A DAY\n" . str_repeat( '=', 124 ) . "\n";

email_shop();
$tcg_a = ebay_order_here();
$tcg_b = ebay_order_here();
$tcg_a->update_status( 'completed' );
$tcg_b->update_status( 'completed' );
$tcg_a->add_order_note( 'Note.', 1 );
check( 'three held back in a day: one line, for the first', array( count( $MAIL ), held_lines(), logged( $HELD_LINE( 'Completed order', 'customer_completed_order', $tcg_a ) ) ), array( 0, 1, true ) );
check( '  its day kept in a transient under the plugin\'s prefix (uninstall deletes _transient_tcgiant_*)', array( TCGiant_Sync_Order_Followthrough::EMAILS_SAID, get_transient( 'tcgiant_followthrough_emails_held' ) ), array( 'tcgiant_followthrough_emails_held', gmdate( 'Y-m-d' ) ) );
$TRANSIENTS['tcgiant_followthrough_emails_held'] = array( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), time() + 3600 );
$tcg_b->add_order_note( 'Note.', 1 );
check( '  the next UTC day: a line again', held_lines(), 2 );
check( '  never a buyer\'s address in the log', logged( '@members.ebay.com' ), false );

echo "\nWHAT THE SETTINGS PAGE DRAWS AND SAVES\n" . str_repeat( '=', 124 ) . "\n";

$tcg_view  = (string) file_get_contents( $ROOT . '/admin/views/settings.php' );
$tcg_admin = (string) file_get_contents( $ROOT . '/admin/class-tcgiant-sync-admin.php' );
check( 'the field, with the order settings, after "Import eBay orders into WooCommerce?": No checked when never chosen', array(
	false !== strpos( $tcg_view, '<input type="radio" name="tcgiant_sync_ebay_settings[ebay_buyer_emails]" value="1" <?php checked( $settings[\'ebay_buyer_emails\'] ?? \'0\', \'1\' ); ?>> Yes' ),
	false !== strpos( $tcg_view, '<input type="radio" name="tcgiant_sync_ebay_settings[ebay_buyer_emails]" value="0" <?php checked( $settings[\'ebay_buyer_emails\'] ?? \'0\', \'0\' ); ?>> No' ),
	strpos( $tcg_view, 'Import eBay orders into WooCommerce?' ) < strpos( $tcg_view, 'Send WooCommerce\\\'s emails to eBay buyers?' ),
	strpos( $tcg_view, 'Send WooCommerce\\\'s emails to eBay buyers?' ) < strpos( $tcg_view, 'Leave positive feedback for eBay buyers' ),
), array( true, true, true, true ) );
check( '  its words (fix round 1: Cancelled and the optional features\' emails held; Order details only by hand; WooCommerce\'s log line)', false !== strpos( $tcg_view, 'With No, WooCommerce\\\'s own emails to the customer - Processing, Completed, On hold, Failed, Cancelled and Customer note, and the review request and fulfilment emails where those WooCommerce features are on - are not sent for orders imported from eBay; WooCommerce\\\'s log notes each as not sent, its "email type is disabled". What you send by hand still goes: Order details, a note to the customer, or an email sent again from the order screen or WooCommerce\\\'s app. Emails to your shop and website orders are not affected, and the Refunded order email is never sent for an eBay order. It applies to every order imported from eBay, even with order import off.' ), true );
preg_match( "/'import' => array\\((.*?)\\n\\t\\t\\),/s", $tcg_admin, $tcg_m );
check( '  saved by the order settings\' form (TCGiant_Sync_Admin::SETTINGS_FORMS, import)', false !== strpos( $tcg_m[1] ?? '', "'ebay_buyer_emails'" ), true );

echo "\nBY HAND THROUGH WOOCOMMERCE'S REST API, AND WITH ITS DEFERRED EMAILS (FIX ROUND 1, REVIEW 1'S M2)\n" . str_repeat( '=', 124 ) . "\n";

foreach ( array( false, true ) as $tcg_def ) {
	$tcg_said = $tcg_def ? 'Deferred emails on' : 'Deferred emails off';

	// WooCommerce's REST "send email" order action (its app's resend).
	email_shop();
	deferred( $tcg_def );
	$tcg_o    = ebay_order_here( 'completed' );
	rest_send_email( $tcg_o, 'customer_completed_order' );
	check( $tcg_said . ': REST send_email of Completed order for an eBay order at No: sent, by hand (its note says it was)', array( $MAIL, held_lines() ), array( array( array( 'customer_completed_order', $tcg_o->get_id(), 'buyer@members.ebay.com' ) ), 0 ) );
	$EMAIL['customer_completed_order']->trigger( $tcg_o->get_id(), $tcg_o );
	$tcg_other = ebay_order_here( 'processing' );
	$tcg_other->update_status( 'completed' );
	check( '  after the request, the same email again, and another order\'s: held back', count( $MAIL ), 1 );

	// A note to the customer: the order screen, REST v3 and REST v4.
	email_shop();
	deferred( $tcg_def );
	$tcg_o    = ebay_order_here();
	ajax_add_note( $tcg_o, 'Shipped today.', true );
	rest_note( $tcg_o, 'Tracking 1Z999.' );
	rest_note( $tcg_o, 'Thank you!', true );
	$tcg_now = mail_ids();
	run_deferred();
	check( '  ' . $tcg_said . ': a note to the customer on the order screen, through REST v3 and through REST v4: all three sent' . ( $tcg_def ? ' - by the later request' : '' ), array( $tcg_now, mail_ids(), held_lines() ), array( $tcg_def ? array() : array( 'customer_note', 'customer_note', 'customer_note' ), array( 'customer_note', 'customer_note', 'customer_note' ), 0 ) );
	check( '  each note kept for its email used up', get_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES ), false );

	// Not by hand: a note added by anything else, and one to another order.
	email_shop();
	deferred( $tcg_def );
	$tcg_o    = ebay_order_here();
	$tcg_p    = ebay_order_here();
	$tcg_o->add_order_note( 'Automatic: your tracking number.', 1 );
	rest_request( '/wc/v3/orders/' . $tcg_o->get_id() . '/notes', array(), function () use ( $tcg_p ) { return $tcg_p->add_order_note( 'Another order.', 1 ); } );
	rest_request( '/wc/v3/orders/' . $tcg_o->get_id(), array(), function () use ( $tcg_o ) { return $tcg_o->add_order_note( 'The order route.', 1 ); } );
	rest_request( '/wc/v3/orders/' . $tcg_o->get_id() . '/notes', array(), function () use ( $tcg_o ) { $tcg_o->update_status( 'completed' ); return true; } );
	run_deferred();
	check( '  ' . $tcg_said . ': a note added by anything else, a REST note request adding to another order, another route, another email in a notes request: all held back', array( count( $MAIL ), held_lines() ), array( 0, 1 ) );

	// The order actions: never deferred.
	email_shop();
	deferred( $tcg_def );
	$INVOICE_ASKS = true;
	$tcg_o        = ebay_order_here();
	order_action( 'send_order_details', $tcg_o );
	check( '  ' . $tcg_said . ': the order screen\'s order actions (as if WooCommerce asked the filter for Order details): sent', mail_ids(), array( 'customer_invoice' ) );
}

// A deferred note matched by its text: one automatic, then one by hand, both
// queued, to the same order - the automatic one sent first.
email_shop();
deferred( true );
$tcg_o    = ebay_order_here();
$tcg_o->add_order_note( 'Automatic.', 1 );
ajax_add_note( $tcg_o, 'By hand.', true );
run_deferred();
check( 'deferred: the note added by hand sent, the automatic one beside it held back - matched by its text', array( $NOTES_SENT, count( $MAIL ), held_lines() ), array( array( 'By hand.' ), 1, 1 ) );
// A website order's note is not kept (nothing to hold back).
email_shop();
deferred( true );
$tcg_w    = website_order_here();
ajax_add_note( $tcg_w, 'Website.', true );
run_deferred();
check( '  a website order\'s note: sent, and nothing kept for it', array( mail_ids(), get_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES ) ), array( array( 'customer_note' ), false ) );
// Kept an hour: an older one no longer passes.
email_shop();
deferred( true );
$tcg_o    = ebay_order_here();
ajax_add_note( $tcg_o, 'Late.', true );
$tcg_kept = get_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES );
$tcg_kept[ $tcg_o->get_id() ][ md5( 'Late.' ) ] = time() - 1;
set_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES, $tcg_kept, 3600 );
run_deferred();
check( '  a note kept more than an hour before its email: held back', array( count( $MAIL ), held_lines() ), array( 0, 1 ) );
// Fix round 2 (review 2's LOW 2): a note email with no text matches no
// kept note - not even one kept for no text - and uses none up.
email_shop();
$tcg_o = ebay_order_here();
set_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES, array( $tcg_o->get_id() => array( md5( 'x' ) => time() + 100, md5( '' ) => time() + 50 ) ), 3600 );
$tcg_e = new WC_Email( 'customer_note', 'Customer note', true );
check( '  a note email with no text (fix round 2): held back, no kept note used up', array( TCGiant_Sync_Order_Followthrough::buyer_email( true, $tcg_o, $tcg_e ), count( get_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES )[ $tcg_o->get_id() ] ) ), array( false, 2 ) );
// Review 2's probe Q3f: WooCommerce's note email off when the shop adds a
// note by hand (sent at once): no copy is kept, so code adding the same
// text within the hour, the email on again, is held back.
email_shop();
$EMAIL['customer_note']->enabled = 'no';
$tcg_o = ebay_order_here();
ajax_add_note( $tcg_o, 'Hello', true );
$tcg_kept = get_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES );
$EMAIL['customer_note']->enabled = 'yes';
$tcg_o->add_order_note( 'Hello', 1 );
check( 'Q3f (fix round 2): the note email off, a note by hand sent at once keeps no copy; the same text by code within the hour: held back', array( $tcg_kept, mail_ids(), held_lines() ), array( false, array(), 1 ) );
deferred( true );
$tcg_o = ebay_order_here();
ajax_add_note( $tcg_o, 'Hello', true );
check( '  with Deferred emails on, the copy is kept (WooCommerce\'s queue on the action)', array_keys( (array) get_transient( TCGiant_Sync_Order_Followthrough::HAND_NOTES ) ), array( $tcg_o->get_id() ) );

echo "\n  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
