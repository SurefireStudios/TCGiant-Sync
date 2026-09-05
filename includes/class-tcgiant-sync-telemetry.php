<?php
/**
 * Reports a site's usage to us, for the internal dashboard.
 *
 * Nothing here is needed for any shop to work. It maintains the counters on
 * our own dashboard and nothing reads a reply. It lived in the cron class,
 * which every edition needs; it does not belong there, because the Lite and
 * Standard editions ship no telemetry at all - WordPress.org requires consent
 * for any phone-home, and the simplest consent is not to ask. So this is its
 * own file, present only in the Pro build.
 *
 * A site can decline: TCGIANT_SYNC_DISABLE_TELEMETRY in wp-config.php, or the
 * tcgiant_sync_telemetry_enabled filter.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The usage ping.
 */
class TCGiant_Sync_Telemetry {

	/**
	 * Single instance.
	 *
	 * @var TCGiant_Sync_Telemetry|null
	 */
	private static $_instance = null;

	/**
	 * @return TCGiant_Sync_Telemetry
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		// Rides the same schedule as the eBay poll, as it always has.
		add_action( 'tcgiant_sync_poll_ebay_cron', array( $this, 'ping_telemetry' ) );
	}

	/**
	 * Whether this site reports its usage back to us.
	 *
	 * There was no way to say no. A site that would rather not be counted, or a
	 * host that forbids outbound calls it did not sanction, had no recourse
	 * short of blocking our address. Both a constant and a filter, because the
	 * first suits a person editing wp-config.php once and the second suits a
	 * host applying a policy across every site it runs.
	 *
	 * @return bool
	 */
	public static function telemetry_enabled() {
		if ( defined( 'TCGIANT_SYNC_DISABLE_TELEMETRY' ) && TCGIANT_SYNC_DISABLE_TELEMETRY ) {
			return false;
		}

		return (bool) apply_filters( 'tcgiant_sync_telemetry_enabled', true );
	}

	/**
	 * Send an absolute telemetry ping to keep dashboard accurate.
	 *
	 * Absolute totals rather than increments, so a ping that never arrives
	 * costs nothing more than a stale figure until the next one. It sends
	 * nothing when the site has declined; see telemetry_enabled().
	 */
	public function ping_telemetry() {
		if ( ! self::telemetry_enabled() ) {
			return;
		}

		$license_data = TCGiant_Sync_License::instance()->get_license_data();
		$license_type = 'free';
		if ( ! empty( $license_data['status'] ) && 'active' === $license_data['status'] ) {
			$license_type = ! empty( $license_data['variant'] ) ? $license_data['variant'] : 'pro';
		}

		// Built once, because the signature has to be over exactly the bytes
		// that get sent.
		$body = wp_json_encode( array(
			'site_url'     => get_site_url(),
			'synced_total' => TCGiant_Sync_License::instance()->get_active_product_count(),
			'pushed_total' => TCGiant_Sync_License::instance()->get_pushed_product_count(),
			'pulled_total' => TCGiant_Sync_License::instance()->get_pulled_product_count(),
			'license_type' => $license_type,
		) );

		$headers = array( 'Content-Type' => 'application/json' );

		// Signed with the key this site was issued when it connected, the same
		// way the relay signs the notices it sends us.
		//
		// The endpoint accepts unsigned pings today and has to: every plugin
		// already installed sends none, and refusing those would only stop the
		// figures arriving. Sending one from here is what makes it possible to
		// require them later, once enough sites have updated.
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );

		if ( ! empty( $settings['relay_secret'] ) ) {
			$timestamp = time();

			$headers['X-TCGiant-Timestamp'] = (string) $timestamp;
			$headers['X-TCGiant-Signature'] = hash_hmac( 'sha256', $body . $timestamp, $settings['relay_secret'] );
		}

		wp_remote_post( 'https://tcgiant.com/syncconnect/telemetry.php', array(
			'user-agent' => TCGiant_Sync_OAuth::user_agent_public(),
			'blocking'   => false,
			'headers'    => $headers,
			'body'       => $body,
		) );
	}
}
