<?php
/**
 * The policy blocks: {{{policy.returns}}} and {{{policy.handling}}}.
 *
 * Rendered from the Business Policies the same item attaches, never typed:
 * WP-Lister's templates ship Shipping, Payment and Service sections of
 * "replace this text" filler, which say whatever the seller once typed while
 * eBay shows the terms the listing really has. Here the block reads the
 * policy IDs that build_item_xml() puts into SellerProfiles - the shop's,
 * with the product's own shipping policy where it has one - so the two cannot
 * disagree. A send with no such policy leaves its block out.
 *
 * Only facts that rarely change: whether returns are accepted, the period,
 * who pays return shipping, and the handling time. Never shipping services
 * or costs, which eBay shows in its own section and which change most often,
 * and no payment block: eBay lets a seller name only methods its checkout
 * offers, and shows them itself.
 *
 * The data is the policy cache that "Fetch / Refresh Policies from eBay" fills
 * (TCGiant_Sync_Exporter::get_policies()), read as stored: rendering a
 * description never asks eBay anything. A policy the cache does not hold, or
 * holds without these fields, leaves its block out with a warning saying to
 * fetch again.
 *
 * English only in this version. The lint says so on eBay Germany, France,
 * Italy and Spain.
 *
 * For 3.28.0: an imported listing's own return and payment policies are
 * recorded on the product (_ebay_return_policy_id and the rest, in the
 * mapper) but not read here, because a 3.27.8 push sends the shop's policies
 * and the block must match what is sent. 3.28.1's item 13, the partial
 * revise, leaves SellerProfiles alone, so from then on the block must render
 * the listing's own policies for those listings.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Template_Policies class.
 */
class TCGiant_Sync_Template_Policies {

	/**
	 * eBay's TimeDuration units a period can be read in, as days or months.
	 * https://developer.ebay.com/api-docs/master/sell/account/openapi/3/sell_account_v1_oas3.json
	 * (TimeDuration: unit, value).
	 */
	const DAY_UNITS = array( 'DAY', 'CALENDAR_DAY', 'BUSINESS_DAY' );

	/**
	 * The two blocks for a send.
	 *
	 * @param array $settings Export settings: return_policy_id and
	 *                        fulfillment_policy_id, as the item sends them.
	 * @param bool  $for_shop For the Blocks screen, which shows the shop's own
	 *                        policies: worded for the shop, not a listing.
	 * @return array{returns: string, handling: string, warnings: array<string, string>}
	 */
	public static function blocks( array $settings, $for_shop = false ) {
		$out = array(
			'returns'  => '',
			'handling' => '',
			'warnings' => array(),
		);

		list( $out['returns'], $why ) = self::returns( (string) ( $settings['return_policy_id'] ?? '' ), $for_shop );
		if ( '' !== $why ) {
			$out['warnings']['returns'] = $why;
		}

		list( $out['handling'], $why ) = self::handling( (string) ( $settings['fulfillment_policy_id'] ?? '' ), $for_shop );
		if ( '' !== $why ) {
			$out['warnings']['handling'] = $why;
		}

		return $out;
	}

	/**
	 * The returns block.
	 *
	 * @param string $id       Return policy ID.
	 * @param bool   $for_shop For the Blocks screen: the shop's policy, not a listing's.
	 * @return array{0: string, 1: string} Markup, and why it was left out.
	 */
	private static function returns( $id, $for_shop = false ) {
		if ( '' === $id ) {
			return array( '', $for_shop
				? __( 'Your shop sends no return policy (Settings, Push to eBay), so the returns block shows nothing.', 'tcgiant-sync' )
				: __( 'No return policy is sent with this listing, so the returns block was left out.', 'tcgiant-sync' ) );
		}

		$policy = self::cached( 'return', $id, $for_shop );
		if ( is_string( $policy ) ) {
			return array( '', $policy );
		}

		if ( ! array_key_exists( 'returns_accepted', $policy ) || null === $policy['returns_accepted'] ) {
			return array( '', self::fetch_again( __( 'return', 'tcgiant-sync' ) ) );
		}

		if ( ! $policy['returns_accepted'] ) {
			return array( self::paragraph( 'tcg-policy-returns', __( 'Returns are not accepted.', 'tcgiant-sync' ) ), '' );
		}

		$days = self::duration( $policy['return_period'] ?? null );
		if ( null === $days ) {
			return array( '', __( 'Your return policy\'s return period could not be read, so the returns block was left out. Press Fetch / Refresh Policies from eBay on the Settings screen again.', 'tcgiant-sync' ) );
		}

		$text = 'MONTH' === $days[1]
			/* translators: %d: months */
			? sprintf( _n( 'Returns accepted within %d month.', 'Returns accepted within %d months.', $days[0], 'tcgiant-sync' ), $days[0] )
			/* translators: %d: days */
			: sprintf( _n( 'Returns accepted within %d day.', 'Returns accepted within %d days.', $days[0], 'tcgiant-sync' ), $days[0] );

		$payer = strtoupper( (string) ( $policy['return_cost_payer'] ?? '' ) );
		if ( 'BUYER' === $payer ) {
			$text .= ' ' . __( 'The buyer pays return shipping.', 'tcgiant-sync' );
		} elseif ( 'SELLER' === $payer ) {
			$text .= ' ' . __( 'We pay return shipping.', 'tcgiant-sync' );
		}

		return array( self::paragraph( 'tcg-policy-returns', $text ), '' );
	}

	/**
	 * The handling-time block.
	 *
	 * @param string $id       Fulfillment policy ID.
	 * @param bool   $for_shop For the Blocks screen: the shop's policy, not a listing's.
	 * @return array{0: string, 1: string} Markup, and why it was left out.
	 */
	private static function handling( $id, $for_shop = false ) {
		if ( '' === $id ) {
			return array( '', $for_shop
				? __( 'Your shop sends no shipping policy (Settings, Push to eBay), so the handling block shows nothing.', 'tcgiant-sync' )
				: __( 'No shipping policy is sent with this listing, so the handling block was left out.', 'tcgiant-sync' ) );
		}

		$policy = self::cached( 'fulfillment', $id, $for_shop );
		if ( is_string( $policy ) ) {
			return array( '', $policy );
		}

		if ( ! array_key_exists( 'handling_time', $policy ) ) {
			return array( '', self::fetch_again( __( 'shipping', 'tcgiant-sync' ) ) );
		}

		$time = self::duration( $policy['handling_time'], true );
		if ( null === $time ) {
			return array( '', __( 'Your shipping policy has no handling time eBay reports (local pickup or freight, perhaps), so the handling block was left out.', 'tcgiant-sync' ) );
		}

		$text = 0 === $time[0]
			? __( 'Ships the same business day as cleared payment.', 'tcgiant-sync' )
			/* translators: %d: business days */
			: sprintf( _n( 'Ships within %d business day of cleared payment.', 'Ships within %d business days of cleared payment.', $time[0], 'tcgiant-sync' ), $time[0] );

		return array( self::paragraph( 'tcg-policy-handling', $text ), '' );
	}

	/**
	 * One policy from the cache "Fetch / Refresh Policies from eBay" fills.
	 *
	 * @param string $type     return or fulfillment.
	 * @param string $id       Policy ID.
	 * @param bool   $for_shop For the Blocks screen: the shop's policy, not a listing's.
	 * @return array|string The policy, or why it cannot be used.
	 */
	public static function cached( $type, $id, $for_shop = false ) {
		$cache = get_option( 'tcgiant_export_policies_' . $type, array() );
		foreach ( is_array( $cache ) ? $cache : array() as $policy ) {
			if ( is_array( $policy ) && (string) ( $policy['id'] ?? '' ) === (string) $id ) {
				return $policy;
			}
		}
		if ( $for_shop ) {
			/* translators: 1: policy kind, 2: policy ID */
			return sprintf( __( 'The %1$s policy your shop sends (%2$s) is not among the policies fetched from eBay, so its block shows nothing. Press Fetch / Refresh Policies from eBay on the Settings screen.', 'tcgiant-sync' ), 'return' === $type ? __( 'return', 'tcgiant-sync' ) : __( 'shipping', 'tcgiant-sync' ), $id );
		}
		/* translators: 1: policy kind, 2: policy ID */
		return sprintf( __( 'The %1$s policy this listing is sent with (%2$s) is not among the policies fetched from eBay, so its block was left out. Press Fetch / Refresh Policies from eBay on the Settings screen.', 'tcgiant-sync' ), 'return' === $type ? __( 'return', 'tcgiant-sync' ) : __( 'shipping', 'tcgiant-sync' ), $id );
	}

	/**
	 * A period in days or months, from eBay's TimeDuration.
	 *
	 * @param mixed $duration   {value, unit}.
	 * @param bool  $allow_zero Whether 0 is a period (a same-day handling time).
	 * @return array{0: int, 1: string}|null Number, and DAY or MONTH.
	 */
	private static function duration( $duration, $allow_zero = false ) {
		if ( ! is_array( $duration ) || ! isset( $duration['value'] ) || ! is_numeric( $duration['value'] ) ) {
			return null;
		}
		$value = (int) $duration['value'];
		$unit  = strtoupper( (string) ( $duration['unit'] ?? 'DAY' ) );
		if ( $value < 0 || ( 0 === $value && ! $allow_zero ) ) {
			return null;
		}
		if ( in_array( $unit, self::DAY_UNITS, true ) ) {
			return array( $value, 'DAY' );
		}
		if ( 'MONTH' === $unit ) {
			return array( $value, 'MONTH' );
		}
		return null;
	}

	/**
	 * Why a cached policy has none of these fields: it was fetched before
	 * 3.27.8 kept them.
	 *
	 * @param string $kind return or shipping.
	 * @return string
	 */
	private static function fetch_again( $kind ) {
		/* translators: %s: policy kind */
		return sprintf( __( 'Your %s policy was fetched before this version kept its details, so its block was left out. Press Fetch / Refresh Policies from eBay on the Settings screen.', 'tcgiant-sync' ), $kind );
	}

	/**
	 * A sentence as a paragraph, styled inline.
	 *
	 * @param string $class Class.
	 * @param string $text  Plain text of ours.
	 * @return string
	 */
	private static function paragraph( $class, $text ) {
		return '<p class="' . $class . '" style="margin:0 0 8px">' . TCGiant_Sync_Template_Renderer::escape( $text ) . '</p>';
	}
}
