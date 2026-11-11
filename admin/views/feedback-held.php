<?php
/**
 * Feedback for eBay buyers held since Emergency Stop (3.37.0): one line on
 * the Import page, drawn from the stored hold alone
 * (TCGiant_Sync_Order_Followthrough::FEEDBACK_HELD_OPTION). Resume Import is
 * shown only while the sync says it stopped, which the next scheduled delta
 * clears, so without this the page would show no sign of the hold.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tc_feedback_held_at = (int) get_option( 'tcgiant_sync_feedback_held', 0 );
if ( $tc_feedback_held_at > 0 ) : ?>
	<div class="notice notice-warning"><p><?php echo esc_html( sprintf(
		/* translators: %s: when Emergency Stop was pressed, UTC */
		__( 'Feedback for eBay buyers is held since Emergency Stop (%s UTC): none is sent until you press Fetch Inventory, or Resume Import when it is shown, or save the order settings on Settings.', 'tcgiant-sync' ),
		gmdate( 'Y-m-d H:i', $tc_feedback_held_at )
	) ); ?></p></div>
<?php endif; ?>
