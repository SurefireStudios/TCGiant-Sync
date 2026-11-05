<?php
/**
 * The price a listing goes to eBay at, and what an import does with eBay's
 * (3.29.0).
 *
 * Until 3.29.0 every push sent the product's WooCommerce regular price, and
 * a shop that wanted another price on eBay had to put it in Regular price
 * and its website price in Sale price - its website then showed every
 * product as on sale. Now a product, or one variation of it, can have an
 * eBay price of its own (_ebay_export_price), and the shop can set one rule
 * for the rest in Settings: a percentage, a fixed amount, or both, with an
 * optional rounding to .99 or a whole number, and tax added for the eBay
 * sites whose prices include it.
 *
 * Every price a push sends eBay is decided here, by for_product(): the
 * StartPrice of an Add, a Revise and a Verify, an auction's opening bid, and
 * each variation's. With no rule, no product price, no tax and no postage
 * taken off, it is the regular price exactly as 3.28 sent it.
 *
 * The other way, an import with "Overwrite Price" on never writes a
 * marked-up eBay price into the website price (import_decision()): a
 * product with its own eBay price has eBay's changes put in that field and
 * its website price left alone; under the rule, the website price changes
 * only when eBay's price has changed since it was last sent or read, and
 * then to the price the rule turns into it - and never to the marked-up
 * price eBay still has after the rule is switched off or a product's own
 * eBay price is cleared.
 *
 * And the postage the import adds to the website price ("Add eBay Shipping
 * Cost to Product Price") is taken off again before the price goes back to
 * eBay. Left on, it was added to the eBay price on every push, and once
 * more to the website price at the next import.
 *
 * Here rather than in the exporter: the import, which every edition ships,
 * reads it too.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Ebay_Price class.
 */
class TCGiant_Sync_Ebay_Price {

	/** A product's or a variation's own eBay price; none means the shop's rule. */
	const OWN_META = '_ebay_export_price';

	/**
	 * eBay's price for the listing (or variation) as it was last sent by a
	 * push or read by an import: how an import tells that eBay's price has
	 * changed.
	 */
	const SEEN_META = '_ebay_price_seen';

	/**
	 * The eBay price a push would have sent at the moment SEEN_META was
	 * written (3.31.1 fix round 1): the price a push sent, or for_product()
	 * once an import has written its own prices. A relist sends a price only
	 * when for_product() differs from both - when the price changed here
	 * since eBay last had it, and not merely because a shop with Overwrite
	 * Price off keeps another price on eBay (TCGiant_Sync_Exporter::
	 * relist_price()).
	 */
	const BASIS_META = '_ebay_price_basis';

	/**
	 * While note_seen() works out the basis: own_price() and baked() read
	 * the product object's meta, with the import's changes not yet saved,
	 * rather than the table.
	 *
	 * @var bool
	 */
	private static $from_object = false;

	/**
	 * The postage the last import that wrote the website price added to it,
	 * which a push takes off again.
	 */
	const BAKED_META = '_ebay_baked_shipping';

	/** Settings: the rule (none, percent, fixed, both) and its figures. */
	const RULE_SETTING     = 'ebay_price_rule';
	const PERCENT_SETTING  = 'ebay_price_percent';
	const FIXED_SETTING    = 'ebay_price_fixed';
	const ROUNDING_SETTING = 'ebay_price_rounding';

	/**
	 * Settings: "eBay prices include tax" - no, auto or yes. Never saved:
	 * auto on a new install, no on a shop updated from before 3.31.1
	 * (tax_default()).
	 */
	const TAX_SETTING = 'ebay_price_tax';

	/**
	 * What decide_tax_default() found (3.31.1): array( default => auto or
	 * no, since => 3.31.1, notice => none, pending - the notice offering Auto
	 * is still due - or auto, kept or chosen once it is not). Autoloaded:
	 * tax_mode() reads it whenever the setting was never saved.
	 */
	const TAX_DEFAULT_OPTION = 'tcgiant_sync_tax_default';

	/** Every setting here, as the Settings screen's export form posts them. */
	const SETTING_KEYS = array( self::RULE_SETTING, self::PERCENT_SETTING, self::FIXED_SETTING, self::ROUNDING_SETTING, self::TAX_SETTING );

	/**
	 * The eBay sites whose buyers see prices with tax in them, by the
	 * marketplace's country: "Auto" adds tax for these and no other.
	 */
	const TAX_COUNTRIES = array( 'GB', 'DE', 'FR', 'IT', 'ES', 'AU' );

	/**
	 * How far, in cents either side, the import looks for the website price
	 * the rule turns into eBay's (reverse()). A rounding to a whole number
	 * maps up to 100 cents of website price to one eBay price, and a
	 * markdown widens that, so 150 covers every rule there is to reverse.
	 */
	const REVERSE_SPAN = 150;

	// -------------------------------------------------------------------------
	// The rule
	// -------------------------------------------------------------------------

	/**
	 * The lowest price a push sends eBay that the rule, tax or a product's
	 * own eBay price made: a lower one is refused before eBay is asked
	 * (floor_refusal()). And, under a rule with a fixed amount, the lowest
	 * website price an import takes from the rule's reverse
	 * (reverse_floor()).
	 */
	const MIN_PRICE = 0.99;

	/**
	 * Whether the eBay price eBay last had (SEEN_META) was sent or read
	 * while the rule, tax or the product's own eBay price made it: '1', or
	 * no meta. An import then never takes it, unchanged, for the website's
	 * price - not after the rule is switched off, nor after the product's
	 * own price is cleared (import_decision()).
	 */
	const MARKED_META = '_ebay_price_marked';

	/**
	 * The shop's rule, read from its settings.
	 *
	 * A rule is a markup: a percentage and an amount of zero or more. A
	 * figure the rule needs that is missing, not a number or below zero -
	 * which the Settings screen refuses (check_settings()) - makes no rule
	 * at all, never a rule of 0. The rounding goes only with a rule.
	 *
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return array{active: bool, percent: float, fixed: float, rounding: string}
	 */
	public static function rule( $settings = null ) {
		$settings = self::settings( $settings );
		$none     = array( 'active' => false, 'percent' => 0.0, 'fixed' => 0.0, 'rounding' => 'none' );
		$kind     = (string) ( $settings[ self::RULE_SETTING ] ?? 'none' );
		if ( ! in_array( $kind, array( 'percent', 'fixed', 'both' ), true ) ) {
			return $none;
		}

		$percent = 0.0;
		$fixed   = 0.0;
		if ( 'fixed' !== $kind ) {
			$percent = self::parse_figure( $settings[ self::PERCENT_SETTING ] ?? '', true );
			if ( null === $percent || $percent < 0 ) {
				return $none;
			}
		}
		if ( 'percent' !== $kind ) {
			$fixed = self::parse_figure( $settings[ self::FIXED_SETTING ] ?? '' );
			if ( null === $fixed || $fixed < 0 ) {
				return $none;
			}
		}
		$rounding = (string) ( $settings[ self::ROUNDING_SETTING ] ?? 'none' );

		return array(
			'active'   => true,
			'percent'  => $percent,
			'fixed'    => $fixed,
			'rounding' => in_array( $rounding, array( '99', 'whole' ), true ) ? $rounding : 'none',
		);
	}

	/**
	 * "eBay prices include tax": 'no' (prices go as they are entered),
	 * 'auto' or 'yes' (tax added, on the eBay sites in TAX_COUNTRIES only).
	 * Never saved, it is tax_default()'s: Auto on an install made with
	 * 3.31.1 or later, No on a shop that ran an earlier version.
	 *
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return string
	 */
	public static function tax_mode( $settings = null ) {
		$settings = self::settings( $settings );
		$mode     = isset( $settings[ self::TAX_SETTING ] ) ? (string) $settings[ self::TAX_SETTING ] : self::tax_default();
		return in_array( $mode, array( 'auto', 'yes' ), true ) ? $mode : 'no';
	}

	/**
	 * "eBay prices include tax" for a shop that has never saved it (3.31.1):
	 * 'auto' where decide_tax_default() found a new install, 'no' otherwise -
	 * a shop updated from an earlier version, or before it has decided.
	 *
	 * @return string
	 */
	public static function tax_default() {
		$record = function_exists( 'get_option' ) ? get_option( self::TAX_DEFAULT_OPTION, array() ) : array();
		return is_array( $record ) && 'auto' === ( $record['default'] ?? '' ) ? 'auto' : 'no';
	}

	/**
	 * Decide, once, what "eBay prices include tax" is for a shop that has
	 * never saved it (3.31.1, the owner's answer to 3.29.0's question 1): at
	 * the first request this version runs, on init.
	 *
	 * A new install is a site with no settings saved at all: no eBay account
	 * ever connected, no form saved, nothing pushed. It starts on Auto.
	 * Every other shop ran an earlier version, pushed its prices with No, and
	 * keeps No until it chooses; on eBay UK, Germany, France, Italy, Spain or
	 * Australia, with WooCommerce's tax on and prices entered without it, it
	 * is offered Auto once (tax_notice_due()). The setup wizard is no sign
	 * of a new install: it is reached only by its address, and saves its own
	 * four settings.
	 *
	 * And no product linked to an eBay listing either (fix round 1): one
	 * query, once, so that a settings row the database failed to give on
	 * that first request does not put a shop with listings on Auto. A query
	 * that fails counts as a shop with listings.
	 *
	 * add_option() is the claim: two first requests at once write one record.
	 *
	 * @return void
	 */
	public static function decide_tax_default() {
		if ( false !== get_option( self::TAX_DEFAULT_OPTION, false ) ) {
			return;
		}

		$new = false === get_option( 'tcgiant_sync_ebay_settings', false );
		if ( $new ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once, at the first request.
			$linked = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1", '_ebay_item_id' ) );
			$new    = null === $linked && '' === (string) $wpdb->last_error;
		}
		add_option( self::TAX_DEFAULT_OPTION, array(
			'default' => $new ? 'auto' : 'no',
			'since'   => '3.31.1',
			'notice'  => $new ? 'none' : 'pending',
		), '', true );
	}

	/**
	 * Whether tax is to be in the price on this shop's eBay site: Yes or
	 * Auto, on a site in TAX_COUNTRIES, whose buyers see prices with VAT or
	 * GST in them. Never on eBay US or Canada, whatever the setting: eBay
	 * adds sales tax there at checkout, and tax in the price would be paid
	 * twice.
	 *
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return bool
	 */
	public static function wants_tax( $settings = null ) {
		if ( 'no' === self::tax_mode( $settings ) || ! class_exists( 'TCGiant_Sync_API' ) ) {
			return false;
		}
		$config = TCGiant_Sync_API::instance()->get_marketplace_config();
		return in_array( (string) ( $config['country'] ?? '' ), self::TAX_COUNTRIES, true );
	}

	/**
	 * The tax to add to a product's website price for eBay, as a fraction
	 * (0.2 for 20%): none unless the shop wants tax in its eBay prices,
	 * WooCommerce calculates tax, its prices are entered without it, and the
	 * product is taxable. The rate is WooCommerce's for the product's tax
	 * class at the shop's base address - the rate the shop charges at home.
	 *
	 * @param WC_Product|null $product    The product, or null for one not made yet (standard rate).
	 * @param array|null      $settings   The plugin's settings, or null for the stored ones.
	 * @return float
	 */
	public static function tax_rate( $product, $settings = null ) {
		if ( ! self::wants_tax( $settings ) ) {
			return 0.0;
		}
		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || ! class_exists( 'WC_Tax' ) ) {
			return 0.0;
		}
		if ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			return 0.0;
		}
		if ( $product instanceof WC_Product && ! $product->is_taxable() ) {
			return 0.0;
		}

		return self::base_rate( $product instanceof WC_Product ? (string) $product->get_tax_class() : '' );
	}

	/**
	 * WooCommerce's tax rate for a tax class at the shop's base address, as
	 * a fraction (0.2 for 20%), whatever "eBay prices include tax" says: the
	 * rate tax_rate() adds, and the one the notice offering Auto names
	 * (3.31.1). 0 where the address has none, or WooCommerce's tax is not
	 * loaded.
	 *
	 * @param string $class The tax class: '' for the standard rate.
	 * @return float
	 */
	public static function base_rate( $class = '' ) {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return 0.0;
		}
		$rates = WC_Tax::get_base_tax_rates( (string) $class );
		if ( empty( $rates ) || ! is_array( $rates ) ) {
			return 0.0;
		}

		// The tax on 100, every rate the base address has (compound ones
		// included), as WooCommerce works it out at its checkout.
		$taxes = WC_Tax::calc_tax( 100, $rates, false );
		$rate  = array_sum( array_map( 'floatval', (array) $taxes ) ) / 100;

		return $rate > 0 ? $rate : 0.0;
	}

	/**
	 * What the rule is, as one string, for the import's settings fingerprint
	 * (TCGiant_Sync_Mapper::settings_fingerprint()): '' when the shop has no
	 * rule and adds no tax on its eBay site, so a shop that uses neither -
	 * eBay US or Canada with Yes or Auto among them - keeps the fingerprint
	 * it had before 3.29.0.
	 *
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return string
	 */
	public static function fingerprint_part( $settings = null ) {
		$settings = self::settings( $settings );
		$rule     = self::rule( $settings );
		$tax      = self::wants_tax( $settings ) ? self::tax_mode( $settings ) : 'no';
		if ( ! $rule['active'] && 'no' === $tax ) {
			return '';
		}
		return implode( '|', array( $rule['percent'], $rule['fixed'], $rule['rounding'], $tax ) );
	}

	// -------------------------------------------------------------------------
	// The price sent to eBay
	// -------------------------------------------------------------------------

	/**
	 * The price a push sends eBay for a product or a variation, as eBay
	 * takes it: two decimal places.
	 *
	 * - Its own eBay price, when it has one: exactly that, with no rule,
	 *   rounding or tax.
	 * - Otherwise its regular price, less the postage an import added to it
	 *   (recorded, whatever the setting says now), with tax added
	 *   where the shop wants it, then the rule and its rounding.
	 * - With none of those, the regular price as every release before 3.29.0
	 *   sent it, byte for byte.
	 *
	 * @param WC_Product $product  A simple product, a variation, or a variable product's parent.
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return string
	 */
	public static function for_product( $product, $settings = null ) {
		$own = self::own_price( $product );
		if ( '' !== $own ) {
			return $own;
		}

		return self::rule_price( $product, $settings );
	}

	/**
	 * The price the shop's rule gives a product or variation, whatever its
	 * own eBay price: what for_product() sends when it has none, and what
	 * its eBay price field shows greyed.
	 *
	 * @param WC_Product $product  A simple product, a variation, or a variable product's parent.
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return string
	 */
	public static function rule_price( $product, $settings = null ) {
		$settings = self::settings( $settings );
		$regular  = $product->get_regular_price();
		$baked    = self::baked( $product, $settings );
		$rule     = self::rule( $settings );
		$tax      = self::tax_rate( $product, $settings );

		if ( 0.0 === $baked && ! $rule['active'] && 0.0 === $tax ) {
			return wc_format_decimal( $regular, 2 );
		}

		// Nothing to work from: as 3.28 sent it.
		if ( ! is_numeric( $regular ) ) {
			return wc_format_decimal( $regular, 2 );
		}

		// Postage that is more than the price is not postage the import
		// added to it: the price goes whole.
		$base = (float) $regular - $baked;
		if ( $base <= 0 ) {
			$base = (float) $regular;
		}

		return self::forward( $base, $rule, $tax );
	}

	/**
	 * The rule applied to a website price: tax added, then the percentage
	 * and the amount, then the rounding. A markdown that would leave the
	 * price at nothing or less is not applied. The rounding never lowers
	 * the price: .99 is the whole number plus .99, and a whole number is the
	 * next one up.
	 *
	 * @param float $base The website price (postage already taken off).
	 * @param array $rule rule().
	 * @param float $tax  tax_rate().
	 * @return string Two decimal places.
	 */
	public static function forward( $base, array $rule, $tax = 0.0 ) {
		$price = (float) $base;
		if ( $tax > 0 ) {
			$price *= 1 + $tax;
		}

		if ( ! empty( $rule['active'] ) ) {
			$marked = $price * ( 1 + (float) $rule['percent'] / 100 ) + (float) $rule['fixed'];
			if ( $marked > 0 ) {
				$price = $marked;
			}
		}

		$cents = self::cents( $price );
		if ( ! empty( $rule['active'] ) ) {
			$cents = self::rounded( $cents, (string) $rule['rounding'] );
		}

		return self::money( $cents );
	}

	/**
	 * The website price the rule turns into a given eBay price: the one
	 * nearest the exact reverse of the rule (amount off, percentage off, tax
	 * off) that the rule takes back to eBay's price to the cent. The reverse
	 * is lossy - many website prices round to one eBay price, and some eBay
	 * prices come from none: 12.50 under .99 rounding, or 10.05 under 20%
	 * tax, whose steps are 1.2 cents - so the candidates near the exact
	 * reverse are each sent forward again and checked.
	 *
	 * The answer says how good it is:
	 *
	 * - 'exact': the rule gives eBay's price from it;
	 * - 'near': none does, and this, the exact reverse to the nearest cent
	 *   (within half a cent of it), gives a price a cent away - the steps of
	 *   a percentage or of tax;
	 * - 'off': none does, because the rule's rounding never gives eBay's
	 *   price (12.50 under .99), or the exact reverse to the cent gives a
	 *   price further away; the answer is the exact reverse to the cent;
	 * - 'none': no website price of reverse_floor() or more gives it. Under
	 *   a rule with a fixed amount, that is MIN_PRICE: eBay's price is at,
	 *   below or just above what the rule adds (1.98 or less under "plus
	 *   1.00"), and a website price of a few cents is no answer - the
	 *   website would sell for cents what eBay sells for more. With tax or a
	 *   percentage alone, only a reverse at or below nothing is none: a
	 *   small website price is eBay's own price less the tax or the markup.
	 *   The price is then ''.
	 *
	 * @param string|float $ebay eBay's price.
	 * @param array        $rule rule().
	 * @param float        $tax  tax_rate().
	 * @return array{0: string, 1: string} The website price, and how good it is.
	 */
	public static function reverse( $ebay, array $rule, $tax = 0.0 ) {
		$target = self::cents( $ebay );
		$floor  = self::reverse_floor( $rule );
		$exact  = (float) $ebay;
		if ( ! empty( $rule['active'] ) ) {
			$exact = ( $exact - (float) $rule['fixed'] ) / ( 1 + (float) $rule['percent'] / 100 );
		}
		if ( $tax > 0 ) {
			$exact /= 1 + $tax;
		}
		$start = self::cents( $exact );

		for ( $step = 0; $step <= self::REVERSE_SPAN; $step++ ) {
			foreach ( 0 === $step ? array( $start ) : array( $start - $step, $start + $step ) as $cents ) {
				if ( $cents >= $floor && self::cents( self::forward( $cents / 100, $rule, $tax ) ) === $target ) {
					return array( self::money( $cents ), 'exact' );
				}
			}
		}

		if ( $start < $floor ) {
			return array( '', 'none' );
		}

		// A price the rule's rounding never gives is the rounding's doing,
		// however near the exact reverse comes: never put down to tax or a
		// percentage.
		if ( ! empty( $rule['active'] ) && self::rounded( $target, (string) $rule['rounding'] ) !== $target ) {
			return array( self::money( $start ), 'off' );
		}

		$back = self::cents( self::forward( $start / 100, $rule, $tax ) );

		return array( self::money( $start ), abs( $back - $target ) <= 1 ? 'near' : 'off' );
	}

	/**
	 * The lowest website price, in cents, that reverse() gives. Only a fixed
	 * amount can take the reverse towards nothing ("plus 1.00" turns eBay's
	 * 1.05 into 0.05), so under a rule with one it is MIN_PRICE. Tax or a
	 * percentage alone only divides eBay's price: a website price under
	 * MIN_PRICE is then eBay's own price less the tax or the markup, and
	 * stands - one cent is the least.
	 *
	 * @param array $rule rule().
	 * @return int
	 */
	public static function reverse_floor( array $rule ) {
		return ! empty( $rule['active'] ) && (float) $rule['fixed'] > 0 ? self::cents( self::MIN_PRICE ) : 1;
	}

	/**
	 * Whether the rule, tax or the product's own eBay price makes the price
	 * a push sends for it: what 3.29.0's settings add. Postage taken off
	 * alone is not one of them - a shop that only uses "Add eBay Shipping
	 * Cost to Product Price" pushes and imports as before, but for the
	 * postage no longer growing the price.
	 *
	 * @param WC_Product $product  The product or variation.
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return bool
	 */
	public static function feature_made( $product, $settings = null ) {
		$settings = self::settings( $settings );
		return '' !== self::own_price( $product ) || self::rule( $settings )['active'] || self::tax_rate( $product, $settings ) > 0;
	}

	/**
	 * Why a push may not send a price: one the rule, tax or the product's
	 * own eBay price made, below MIN_PRICE. A price that is simply the
	 * regular price, or the regular price less postage an import added,
	 * goes as it did before 3.29.0, and eBay judges it.
	 *
	 * @param WC_Product $product The product or variation.
	 * @param string     $price   The price for_product() gave it.
	 * @return WP_Error|null
	 */
	public static function floor_refusal( $product, $price ) {
		if ( (float) $price >= self::MIN_PRICE || (string) $price === wc_format_decimal( $product->get_regular_price(), 2 ) || ! self::feature_made( $product ) ) {
			return null;
		}

		return new WP_Error(
			'ebay_price_too_low',
			sprintf(
				/* translators: 1: the product or variation, 2: the price, 3: the lowest price */
				__( 'The eBay price of %1$s would be %2$s, below %3$s, the lowest TCGiant Sync sends eBay from the eBay price rule, tax or an eBay price of its own. Raise its price or its eBay price, then push again. Nothing was sent to eBay.', 'tcgiant-sync' ),
				sprintf( 'WC #%d', (int) $product->get_id() ),
				(string) $price,
				self::money( self::cents( self::MIN_PRICE ) )
			)
		);
	}

	/**
	 * Whether a pushed live auction's revise sends its StartPrice: when it is
	 * the regular price, as every release before 3.29.0 sent it, or the
	 * regular price less postage an import added. One the rule, tax or the
	 * product's own eBay price makes is a new opening bid, which eBay
	 * refuses once there are bids: a rule switched on must not move every
	 * running auction's opening bid. A new listing gets the rule's price.
	 *
	 * @param WC_Product $product The product.
	 * @param string     $price   The price for_product() gave it.
	 * @return bool
	 */
	public static function revises_auction_price( $product, $price ) {
		return (string) $price === wc_format_decimal( $product->get_regular_price(), 2 ) || ! self::feature_made( $product );
	}

	/**
	 * The product's or variation's own eBay price, two decimal places, or ''
	 * when it has none (or one that is not a price above nothing). Read as
	 * parse_figure() reads a figure, so "12,50" or "12.50 " written by
	 * WooCommerce's CSV import is a price too.
	 *
	 * @param WC_Product|int $product The product or its ID.
	 * @return string
	 */
	public static function own_price( $product ) {
		$id = $product instanceof WC_Product ? (int) $product->get_id() : (int) $product;
		if ( self::$from_object && $product instanceof WC_Product ) {
			$raw = self::parse_figure( $product->get_meta( self::OWN_META ) );
		} else {
			$raw = $id > 0 ? self::parse_figure( get_post_meta( $id, self::OWN_META, true ) ) : null;
		}
		if ( null === $raw || $raw <= 0 ) {
			return '';
		}
		return self::money( self::cents( $raw ) );
	}

	/**
	 * The postage to take off a product's website price before it goes to
	 * eBay: what the last import that wrote the website price added to it.
	 * Whatever "Add eBay Shipping Cost to Product Price" says now: while the
	 * record is there the website price holds that postage, and only an
	 * import writing a price without it takes the record away
	 * (note_baked()). Sent whole after the setting was turned off, it raised
	 * eBay's price by the postage.
	 *
	 * @param WC_Product $product  The product or variation.
	 * @param array|null $settings Unused; kept for callers that pass them.
	 * @return float
	 */
	public static function baked( $product, $settings = null ) {
		$raw = self::$from_object ? $product->get_meta( self::BAKED_META ) : get_post_meta( (int) $product->get_id(), self::BAKED_META, true );
		return is_numeric( $raw ) && (float) $raw > 0 ? (float) $raw : 0.0;
	}

	/**
	 * Note the prices a push sent eBay, as eBay's last known prices: by the
	 * product's own save, or straight to the table for its variations.
	 * Nothing for a push that sent none - an imported live auction's revise
	 * leaves its opening bid as it is (3.28.8). Each is marked (MARKED_META)
	 * when it is not the plain regular price and the rule, tax or the
	 * product's own eBay price made it (feature_made()). Postage taken off
	 * alone does not mark it: that shop's import writes eBay's price, with
	 * the postage it adds now, into the website price, as before 3.29.0.
	 *
	 * A relist (3.31.1) gives no product: each price goes straight to the
	 * table, as a push's variations' do.
	 *
	 * @param WC_Product|null $product The product pushed, before its save; null for a relist.
	 * @param array           $prices  Product or variation ID => the price sent.
	 */
	public static function record_sent( $product, array $prices ) {
		foreach ( $prices as $id => $price ) {
			if ( ! is_numeric( $price ) ) {
				continue;
			}
			$own    = $product instanceof WC_Product && (int) $id === (int) $product->get_id();
			$item   = $own ? $product : wc_get_product( (int) $id );
			$marked = $item && (string) $price !== wc_format_decimal( $item->get_regular_price(), 2 ) && self::feature_made( $item );
			// The price sent is the one a push would send now: the basis
			// too (3.31.1 fix round 1).
			if ( $own && $product instanceof WC_Product ) {
				$product->update_meta_data( self::SEEN_META, (string) $price );
				$product->update_meta_data( self::BASIS_META, (string) $price );
				if ( $marked ) {
					$product->update_meta_data( self::MARKED_META, '1' );
				} else {
					$product->delete_meta_data( self::MARKED_META );
				}
			} else {
				update_post_meta( (int) $id, self::SEEN_META, (string) $price );
				update_post_meta( (int) $id, self::BASIS_META, (string) $price );
				if ( $marked ) {
					update_post_meta( (int) $id, self::MARKED_META, '1' );
				} else {
					delete_post_meta( (int) $id, self::MARKED_META );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// The import
	// -------------------------------------------------------------------------

	/**
	 * What an import does with eBay's price for a product or a variation.
	 *
	 * - 'today': no own eBay price, no rule and no tax: the import writes
	 *   eBay's price into the website price as it always has, when
	 *   "Overwrite Price" is on or the product is new.
	 * - 'held': the same, but eBay's price is still the one recorded while
	 *   the rule, tax or the product's own eBay price made it (MARKED_META) -
	 *   the rule since switched off, or the own price cleared. It is not
	 *   taken for the website's price: only a change on eBay is.
	 * - 'own': the product has its own eBay price. With "Overwrite Price"
	 *   on, a change eBay has made since the price was last sent or read is
	 *   written into that field, and the website price is never touched.
	 * - 'rule': under the shop's rule (or tax), a new product's website price
	 *   is the one the rule turns into eBay's (reverse()). An existing one's
	 *   changes, with "Overwrite Price" on, only when eBay's price has
	 *   changed since it was last sent or read and the rule does not already
	 *   give it from the website price. With no record of eBay's price -
	 *   every product the first time the rule is on - nothing is known to have
	 *   changed, and the website price is kept: a rule switched on must not
	 *   take its markup off every website price at the next import. (A seller's
	 *   own eBay edit made before the listing's price was first recorded is
	 *   kept off the website too, and the next push sends the rule's price.)
	 *   Where no website price of reverse_floor() or more gives eBay's
	 *   price, or its markdown (under a fixed amount, eBay's is at, below or
	 *   just above what the rule adds), neither is written from the reverse: an existing product keeps its
	 *   website price and its sale price, a new one takes eBay's for both,
	 *   and the log says why.
	 *
	 * 'marked' says whether eBay's price, as noted now (note_seen()), is one
	 * the rule, tax or an own price made.
	 *
	 * @param WC_Product|null $product   The product or variation, or null for a new one.
	 * @param string          $ebay      eBay's price, as a push sends it back: its StartPrice, or a markdown's original price - without postage.
	 * @param string          $ebay_sale eBay's markdown price, without postage, or ''.
	 * @param float           $shipping  The postage the import adds to the website price, or 0.
	 * @param bool            $is_new    Whether the import is making the product (or variation) now.
	 * @param bool            $overwrite "Overwrite Price".
	 * @param array|null      $settings  The plugin's settings, or null for the stored ones.
	 * @return array{mode: string, regular: ?string, sale: ?string, own: ?string, baked: ?float, note: string, warning: string, marked: bool}
	 */
	public static function import_decision( $product, $ebay, $ebay_sale, $shipping, $is_new, $overwrite, $settings = null ) {
		$settings = self::settings( $settings );
		$out      = array( 'mode' => 'today', 'regular' => null, 'sale' => null, 'own' => null, 'baked' => null, 'note' => '', 'warning' => '', 'marked' => false );
		$ebay     = (string) $ebay;
		$shipping = max( 0.0, (float) $shipping );
		$known    = $product instanceof WC_Product && ! $is_new;
		$own      = $known ? self::own_price( $product ) : '';
		$rule     = self::rule( $settings );
		$tax      = self::tax_rate( $known ? $product : null, $settings );
		$seen     = $known ? self::seen( $product ) : '';
		$changed  = '' !== $seen && is_numeric( $ebay ) && self::cents( $ebay ) !== self::cents( $seen );

		if ( '' !== $own ) {
			$out['mode'] = 'own';
		} elseif ( $rule['active'] || $tax > 0 ) {
			$out['mode'] = 'rule';
		} else {
			// eBay's price still the one the rule, tax or an own price made,
			// and unchanged since: never the website's.
			$out['marked'] = $known && '' !== $seen && ! $changed && '1' === (string) $product->get_meta( self::MARKED_META );
			if ( $out['marked'] && $overwrite ) {
				$out['mode'] = 'held';
				$out['note'] = 'Price kept: eBay\'s price is still the one the eBay price rule, tax or an eBay price of its own made, and has not changed on eBay since';
			}
			return $out;
		}
		$out['marked'] = true;

		// eBay sent no price to go by: nothing changes.
		if ( ! is_numeric( $ebay ) || (float) $ebay <= 0 ) {
			return $out;
		}

		if ( $known && ! $overwrite ) {
			$out['note'] = 'Price skipped (WooCommerce won)';
			return $out;
		}

		if ( 'own' === $out['mode'] ) {
			if ( self::cents( $ebay ) === self::cents( $own ) ) {
				$out['note'] = 'Price kept: eBay has this product\'s own eBay price';
			} elseif ( $changed ) {
				$out['own']  = self::money( self::cents( $ebay ) );
				$out['note'] = sprintf( 'eBay price updated to %s (eBay won); the website price is left as it is', $out['own'] );
			} else {
				$out['note'] = 'Price kept: this product has its own eBay price, and eBay\'s has not changed';
			}
			return $out;
		}

		if ( $known ) {
			if ( self::cents( self::for_product( $product, $settings ) ) === self::cents( $ebay ) ) {
				$out['note'] = 'Price kept: eBay\'s price is the website price under the eBay price rule';
				return $out;
			}
			if ( '' === $seen ) {
				$out['note'] = 'Price kept: eBay\'s price was not known before, so it is not taken as a change';
				return $out;
			}
			if ( ! $changed ) {
				$out['note'] = 'Price kept: eBay\'s price has not changed since it was last sent or read';
				return $out;
			}
		}

		$shown    = self::money( self::cents( $ebay ) );
		$has_sale = is_numeric( $ebay_sale ) && (float) $ebay_sale > 0;
		$markdown = $has_sale ? self::money( self::cents( $ebay_sale ) ) : '';
		list( $regular, $how ) = self::reverse( $ebay, $rule, $tax );
		list( $sale, $sale_how ) = $has_sale ? self::reverse( $ebay_sale, $rule, $tax ) : array( '', '' );

		// No website price of reverse_floor() or more gives eBay's price, or
		// its markdown: neither is written from the reverse, so a fixed
		// amount's website price of cents, or a sale price above the regular
		// one, never is. A
		// product here keeps both of its prices; a new one takes eBay's, as
		// it would with no rule.
		if ( 'none' === $how || 'none' === $sale_how ) {
			$what  = 'none' === $how ? 'price of ' . $shown : 'markdown price of ' . $markdown;
			$cause = self::cause_words( $rule, $tax );
			$floor = self::money( self::reverse_floor( $rule ) );
			if ( $known ) {
				$kept = (string) $product->get_regular_price();
				if ( '' !== (string) $product->get_sale_price() ) {
					$kept .= ', with its sale price of ' . (string) $product->get_sale_price() . ',';
				}
				$out['note']    = sprintf( 'Price kept: no website price of %1$s or more gives eBay\'s %2$s under %3$s', $floor, $what, $cause );
				$out['warning'] = sprintf(
					'No website price of %1$s or more gives eBay\'s %2$s under %3$s: the website price is kept at %4$s and the next push sends eBay %5$s. Give the product its own eBay price to keep %6$s.',
					$floor,
					$what,
					$cause,
					$kept,
					self::for_product( $product, $settings ),
					$shown
				);
				return $out;
			}
			$out['regular'] = self::with_postage( $shown, $shipping );
			$out['sale']    = $has_sale ? self::with_postage( $markdown, $shipping ) : '';
			$out['baked']   = $shipping;
			$out['warning'] = sprintf(
				'No website price of %1$s or more gives eBay\'s %2$s under %3$s: the new product takes eBay\'s %4$s as its website price%5$s, and the next push sends eBay %6$s. Give the product its own eBay price to keep %4$s.',
				$floor,
				$what,
				$cause,
				$shown,
				$has_sale ? ' and eBay\'s ' . $markdown . ' as its sale price' : '',
				self::forward( (float) $shown, $rule, $tax )
			);
			return $out;
		}

		if ( 'off' === $how ) {
			$out['warning'] = sprintf(
				'eBay\'s price of %1$s cannot come from %2$s, so the website price was set to %3$s, the nearest: the next push sends eBay %4$s. Give the product its own eBay price to keep %1$s.',
				$shown,
				'none' !== (string) $rule['rounding'] ? 'the eBay price rule (its rounding never gives it)' : self::cause_words( $rule, $tax ),
				$regular,
				self::forward( (float) $regular, $rule, $tax )
			);
		} elseif ( 'near' === $how ) {
			// Within a cent: the steps of tax or a percentage on a price in
			// whole cents, named as the cause.
			$cause = array();
			if ( $tax > 0 ) {
				$cause[] = self::percent_words( $tax * 100 ) . '% tax';
			}
			if ( 0.0 !== (float) $rule['percent'] ) {
				$cause[] = 'the eBay price rule\'s ' . self::percent_words( (float) $rule['percent'] ) . '%';
			}
			$out['warning'] = sprintf(
				'eBay\'s price of %1$s is not one that %2$s gives from a website price in whole cents, so the website price was set to %3$s, the nearest: the next push sends eBay %4$s. Give the product its own eBay price to keep %1$s.',
				$shown,
				$cause ? implode( ' and ', $cause ) : 'the eBay price rule',
				$regular,
				self::forward( (float) $regular, $rule, $tax )
			);
		}

		$out['regular'] = self::with_postage( $regular, $shipping );
		$out['sale']    = $has_sale ? self::with_postage( $sale, $shipping ) : '';
		$out['baked']   = $shipping;

		if ( $known ) {
			$out['note'] = sprintf( 'Price updated (eBay won): eBay\'s %1$s is the eBay price rule\'s price for %2$s', $shown, $out['regular'] );
		}

		return $out;
	}

	/**
	 * Carry out import_decision() on a product or variation, before its
	 * save: its website price and sale price, its own eBay price, and the
	 * postage now in the website price.
	 *
	 * @param WC_Product $product  The product or variation.
	 * @param array      $decision import_decision().
	 * @param string     $item_id  The eBay listing, for the log.
	 * @return string[] Lines for the product's sync log.
	 */
	public static function apply_import( $product, array $decision, $item_id = '' ) {
		if ( null !== $decision['regular'] ) {
			$product->set_regular_price( $decision['regular'] );
		}
		if ( null !== $decision['sale'] ) {
			$product->set_sale_price( (string) $decision['sale'] );
		}
		if ( null !== $decision['own'] ) {
			$product->update_meta_data( self::OWN_META, $decision['own'] );
		}
		if ( null !== $decision['baked'] ) {
			self::note_baked( $product, (float) $decision['baked'] );
		}
		if ( '' !== $decision['warning'] && class_exists( 'TCGiant_Sync_Logger' ) ) {
			TCGiant_Sync_Logger::warning( sprintf( '%1$s (eBay listing %2$s): %3$s', $product->get_id() ? sprintf( 'WC #%d', (int) $product->get_id() ) : 'A product made from eBay', (string) $item_id, $decision['warning'] ) );
		}

		return '' !== $decision['note'] ? array( $decision['note'] ) : array();
	}

	/**
	 * Note the postage an import has just put into the website price, for a
	 * push to take off again: none written means none to take off.
	 *
	 * @param WC_Product $product  The product or variation, before its save.
	 * @param float      $shipping The postage added, or 0.
	 */
	public static function note_baked( $product, $shipping ) {
		if ( (float) $shipping > 0 ) {
			$product->update_meta_data( self::BAKED_META, self::money( self::cents( $shipping ) ) );
		} elseif ( '' !== (string) $product->get_meta( self::BAKED_META ) ) {
			$product->delete_meta_data( self::BAKED_META );
		}
	}

	/**
	 * Note eBay's price, as an import has just read it, before the save, and
	 * whether the rule, tax or an own price made it (MARKED_META).
	 *
	 * @param WC_Product $product The product or variation.
	 * @param string     $ebay    eBay's price, without postage.
	 * @param bool       $marked  import_decision()'s 'marked'.
	 */
	public static function note_seen( $product, $ebay, $marked = false ) {
		if ( ! is_numeric( $ebay ) || (float) $ebay <= 0 ) {
			return;
		}
		$product->update_meta_data( self::SEEN_META, self::money( self::cents( $ebay ) ) );

		// And what a push would send now (3.31.1 fix round 1): called after
		// the import's own price writes, which are not saved yet, so read
		// from the product as it stands. With Overwrite Price off that is
		// still the shop's own price, which a relist then keeps from
		// replacing eBay's.
		self::$from_object = true;
		try {
			$basis = self::for_product( $product );
		} finally {
			self::$from_object = false;
		}
		if ( is_numeric( $basis ) ) {
			$product->update_meta_data( self::BASIS_META, self::money( self::cents( $basis ) ) );
		} else {
			$product->delete_meta_data( self::BASIS_META );
		}
		if ( $marked ) {
			$product->update_meta_data( self::MARKED_META, '1' );
		} elseif ( '' !== (string) $product->get_meta( self::MARKED_META ) ) {
			$product->delete_meta_data( self::MARKED_META );
		}
	}

	/**
	 * eBay's price as last sent or read, two decimal places, or ''.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return string
	 */
	public static function seen( $product ) {
		$raw = $product->get_meta( self::SEEN_META );
		return is_numeric( $raw ) && (float) $raw > 0 ? self::money( self::cents( $raw ) ) : '';
	}

	/**
	 * What a push would have sent when eBay's price was last noted
	 * (BASIS_META), two decimal places, or '' for a note written before
	 * 3.31.1 fix round 1.
	 *
	 * @param WC_Product $product The product or variation.
	 * @return string
	 */
	public static function basis( $product ) {
		$raw = $product->get_meta( self::BASIS_META );
		return is_numeric( $raw ) && (float) $raw >= 0 ? self::money( self::cents( $raw ) ) : '';
	}

	/**
	 * "Overwrite Price" (Import Settings): on unless saved as anything but
	 * '1', as the import reads it.
	 *
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return bool
	 */
	public static function overwrites_price( $settings = null ) {
		$settings = self::settings( $settings );
		return '1' === (string) ( isset( $settings['overwrite_price'] ) ? $settings['overwrite_price'] : '1' );
	}

	// -------------------------------------------------------------------------
	// What the admin shows
	// -------------------------------------------------------------------------

	/**
	 * The price a push would send eBay for a product, and how it was
	 * arrived at, in words. A variable product's is each variation's: the
	 * lowest and highest.
	 *
	 * @param WC_Product $product The product.
	 * @return array{price: string, high: string, source: string, how: string}
	 */
	public static function explain( $product ) {
		$settings = self::settings( null );

		if ( $product->is_type( 'variable' ) ) {
			$prices = array();
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && 'publish' === $child->get_status() ) {
					$prices[] = (float) self::for_product( $child, $settings );
				}
			}
			if ( ! $prices ) {
				return array( 'price' => '', 'high' => '', 'source' => 'variations', 'how' => __( 'Each variation\'s, set under Variations.', 'tcgiant-sync' ) );
			}
			return array(
				'price'  => self::money( self::cents( min( $prices ) ) ),
				'high'   => self::money( self::cents( max( $prices ) ) ),
				'source' => 'variations',
				'how'    => __( 'Each variation\'s, set under Variations: its own eBay price, or its regular price under the rule in Settings.', 'tcgiant-sync' ),
			);
		}

		$price = self::for_product( $product, $settings );
		if ( '' !== self::own_price( $product ) ) {
			return array( 'price' => $price, 'high' => $price, 'source' => 'own', 'how' => __( 'This product\'s own eBay price.', 'tcgiant-sync' ) );
		}

		$parts = array();
		if ( self::baked( $product, $settings ) > 0 ) {
			/* translators: %s: postage, as a number */
			$parts[] = sprintf( __( 'less the %s postage the import added to it', 'tcgiant-sync' ), self::money( self::cents( self::baked( $product, $settings ) ) ) );
		}
		$tax = self::tax_rate( $product, $settings );
		if ( $tax > 0 ) {
			/* translators: %s: tax rate, e.g. 20 */
			$parts[] = sprintf( __( 'with %s%% tax added', 'tcgiant-sync' ), rtrim( rtrim( number_format( $tax * 100, 2, '.', '' ), '0' ), '.' ) );
		}
		$rule = self::rule( $settings );
		if ( $rule['active'] ) {
			$parts[] = self::rule_words( $rule );
		}

		if ( ! $parts ) {
			return array( 'price' => $price, 'high' => $price, 'source' => 'website', 'how' => __( 'The regular price.', 'tcgiant-sync' ) );
		}

		return array(
			'price'  => $price,
			'high'   => $price,
			'source' => 'rule',
			/* translators: %s: what is done to the regular price, e.g. "plus 10%, rounded to .99" */
			'how'    => sprintf( __( 'The regular price, %s.', 'tcgiant-sync' ), implode( ', ', $parts ) ),
		);
	}

	/**
	 * The rule in words: "plus 10% and 0.50, rounded to .99".
	 *
	 * @param array $rule rule().
	 * @return string
	 */
	public static function rule_words( array $rule ) {
		$bits = array();
		if ( 0.0 !== (float) $rule['percent'] ) {
			/* translators: %s: a percentage, e.g. 10 */
			$bits[] = sprintf( __( 'plus %s%%', 'tcgiant-sync' ), rtrim( rtrim( number_format( (float) $rule['percent'], 2, '.', '' ), '0' ), '.' ) );
		}
		if ( 0.0 !== (float) $rule['fixed'] ) {
			/* translators: %s: an amount, e.g. 0.50 */
			$bits[] = sprintf( __( 'plus %s', 'tcgiant-sync' ), number_format( (float) $rule['fixed'], 2, '.', '' ) );
		}
		if ( 'whole' === $rule['rounding'] ) {
			$bits[] = __( 'rounded up to a whole number', 'tcgiant-sync' );
		} elseif ( '99' === $rule['rounding'] ) {
			$bits[] = __( 'rounded up to .99', 'tcgiant-sync' );
		}
		return $bits ? implode( ', ', $bits ) : __( 'unchanged by the rule', 'tcgiant-sync' );
	}

	/**
	 * What turns a website price into an eBay price, for the import's log:
	 * "20% tax and the eBay price rule (plus 1.00)".
	 *
	 * @param array $rule rule().
	 * @param float $tax  tax_rate().
	 * @return string
	 */
	private static function cause_words( array $rule, $tax ) {
		$parts = array();
		if ( $tax > 0 ) {
			$parts[] = self::percent_words( $tax * 100 ) . '% tax';
		}
		if ( ! empty( $rule['active'] ) ) {
			$parts[] = 'the eBay price rule (' . self::rule_words( $rule ) . ')';
		}
		return $parts ? implode( ' and ', $parts ) : 'the eBay price rule';
	}

	/**
	 * The Listings screen's price cell: the price a push sends eBay. Where
	 * none of 3.29.0's settings makes it (no product price, no rule, no
	 * tax), the figure the screen showed before 3.29.0 - also for a shop
	 * that only takes postage off. An auction's row says its opening bid
	 * goes with a new listing only.
	 *
	 * @param int        $product_id The row's product.
	 * @param string|int $fallback   The figure the row's query gave.
	 * @return string HTML.
	 */
	public static function listing_cell( $product_id, $fallback ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ! self::in_use( $product ) ) {
			return wc_price( $fallback );
		}

		$shown = self::explain( $product );
		if ( '' === $shown['price'] ) {
			return wc_price( $fallback );
		}

		$html = wc_price( (float) $shown['price'] );
		if ( $shown['high'] !== $shown['price'] ) {
			$html .= ' &ndash; ' . wc_price( (float) $shown['high'] );
		}
		if ( 'Chinese' === (string) get_post_meta( (int) $product_id, '_ebay_listing_type', true ) ) {
			/* translators: %s: how the eBay price is made, e.g. "This product's own eBay price." */
			$title = sprintf( __( 'The opening bid a new listing is sent; an Update leaves a running auction\'s as it is. %s', 'tcgiant-sync' ), $shown['how'] );
		} else {
			/* translators: %s: how the eBay price is made, e.g. "This product's own eBay price." */
			$title = sprintf( __( 'Sent to eBay. %s', 'tcgiant-sync' ), $shown['how'] );
		}
		return '<span title="' . esc_attr( $title ) . '">' . $html . '</span>';
	}

	/**
	 * Whether any of 3.29.0's settings makes this product's eBay price: its
	 * own (or a variation's), the rule, or tax. Postage recorded to take off
	 * is not one: a shop that only adds postage on import sees its Listings
	 * screen as before 3.29.0.
	 *
	 * @param WC_Product $product The product.
	 * @return bool
	 */
	public static function in_use( $product ) {
		$settings = self::settings( null );
		if ( self::rule( $settings )['active'] || self::wants_tax( $settings ) ) {
			return true;
		}
		$ids = $product->is_type( 'variable' ) ? (array) $product->get_children() : array( $product->get_id() );
		foreach ( $ids as $id ) {
			if ( '' !== self::own_price( (int) $id ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Admin fields
	// -------------------------------------------------------------------------

	/**
	 * The eBay price fields' hooks: the variation field and every save. The
	 * product's own field is drawn on its eBay tab by
	 * TCGiant_Sync_Admin::render_ebay_listing_panel(). And the notice
	 * offering Auto for tax, with its answer (3.31.1).
	 */
	public static function register_admin() {
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_field' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_field' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_tax_notice' ) );
		add_action( 'admin_post_tcgiant_tax_notice', array( __CLASS__, 'handle_tax_notice' ) );
	}

	/**
	 * Set or clear a product's or variation's own eBay price from what the
	 * shop typed: a price above nothing, in the shop's own decimal
	 * separator, or empty for the rule.
	 *
	 * @param int    $product_id Product or variation.
	 * @param string $typed      What was typed.
	 */
	public static function set_own( $product_id, $typed ) {
		$typed = trim( (string) $typed );
		$value = '' === $typed ? '' : wc_format_decimal( $typed );
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			delete_post_meta( (int) $product_id, self::OWN_META );
			return;
		}
		update_post_meta( (int) $product_id, self::OWN_META, self::money( self::cents( $value ) ) );
	}

	/**
	 * Save the eBay price a form posted, when it posted one: the product
	 * screen's own save, or the eBay tab's Push and Verify buttons, which
	 * send what is typed before the product is updated.
	 *
	 * @param int    $product_id The product.
	 * @param array  $posted     $_POST.
	 * @param string $key        The field's name in it.
	 */
	public static function save_posted( $product_id, array $posted, $key ) {
		if ( ! isset( $posted[ $key . '_shown' ] ) || ! isset( $posted[ $key ] ) || ! is_string( $posted[ $key ] ) ) {
			return;
		}
		self::set_own( (int) $product_id, sanitize_text_field( wp_unslash( $posted[ $key ] ) ) );
	}

	/**
	 * woocommerce_process_product_meta: the eBay tab's own field.
	 *
	 * @param int $post_id The product.
	 */
	public static function save_product_field( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked its own nonce before this action.
		self::save_posted( (int) $post_id, $_POST, self::OWN_META );
	}

	/**
	 * woocommerce_save_product_variation: a variation's field.
	 *
	 * @param int $variation_id The variation.
	 * @param int $i            Its place in the form.
	 */
	public static function save_variation_field( $variation_id, $i ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked its own nonce before this action.
		if ( isset( $_POST[ self::OWN_META . '_shown' ][ $i ], $_POST[ self::OWN_META ][ $i ] ) && is_string( $_POST[ self::OWN_META ][ $i ] ) ) {
			self::set_own( (int) $variation_id, sanitize_text_field( wp_unslash( $_POST[ self::OWN_META ][ $i ] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * woocommerce_variation_options_pricing: a variation's eBay price, under
	 * its own prices.
	 *
	 * @param int     $loop           Its place in the form.
	 * @param array   $variation_data Its data.
	 * @param WP_Post $variation      Its post.
	 */
	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$id        = (int) $variation->ID;
		$own       = self::own_price( $id );
		$child     = wc_get_product( $id );
		$rule_says = $child ? self::rule_price( $child ) : '';

		echo '<input type="hidden" name="' . esc_attr( self::OWN_META . '_shown[' . (int) $loop . ']' ) . '" value="1">';
		woocommerce_wp_text_input( array(
			'id'            => self::OWN_META . '_' . (int) $loop,
			'name'          => self::OWN_META . '[' . (int) $loop . ']',
			/* translators: %s: currency symbol */
			'label'         => sprintf( __( 'eBay price (%s)', 'tcgiant-sync' ), self::currency_symbol() ),
			'value'         => '' !== $own ? self::localized( $own ) : '',
			'placeholder'   => '' !== $rule_says ? self::localized( $rule_says ) : '',
			'data_type'     => 'price',
			'wrapper_class' => 'form-row form-row-full',
			'desc_tip'      => true,
			'description'   => __( 'What this variation sells for on eBay. Leave it empty to send its regular price, under the eBay price rule in Settings if there is one (shown greyed). An import with Overwrite Price on puts eBay\'s price changes here, and leaves the website price alone.', 'tcgiant-sync' ),
		) );
	}

	/**
	 * The eBay price section of a product's eBay tab: the field, and what a
	 * push would send eBay.
	 *
	 * @param WC_Product $product The product.
	 */
	public static function render_product_section( $product ) {
		$product_id = (int) $product->get_id();
		$shown      = self::explain( $product );
		$variable   = $product->is_type( 'variable' );
		$summary    = '' === $shown['price'] ? '' : ( $shown['high'] !== $shown['price'] ? $shown['price'] . ' - ' . $shown['high'] : $shown['price'] );
		$auction    = 'Chinese' === (string) get_post_meta( $product_id, '_ebay_listing_type', true ) && class_exists( 'TCGiant_Sync_Ebay_Live' ) && ! TCGiant_Sync_Ebay_Live::follows_defaults( $product_id );
		?>
		<div class="tc-ebay-section" id="tc-section-ebay-price">
			<div class="tc-ebay-section-head" data-target="tc-body-ebay-price">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( 'eBay Price', 'tcgiant-sync' ); ?></span>
				<span id="tc-ebay-price-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php
					/* translators: %s: the price sent to eBay */
					echo '' !== $summary ? esc_html( sprintf( __( 'Sent to eBay: %s', 'tcgiant-sync' ), $summary ) ) : '';
				?></span>
			</div>
			<div class="tc-ebay-section-body" id="tc-body-ebay-price">
				<?php if ( $variable ) : ?>
					<p style="margin:0 0 6px;font-size:12px;color:#555;"><?php esc_html_e( 'Each variation has its own eBay price, in its own prices under Variations. Leave one empty to send its regular price, under the eBay price rule in Settings if there is one.', 'tcgiant-sync' ); ?></p>
				<?php else : ?>
					<?php
					$rule_says = self::rule_price( $product );
					$own       = self::own_price( $product );
					echo '<input type="hidden" name="' . esc_attr( self::OWN_META . '_shown' ) . '" value="1">';
					woocommerce_wp_text_input( array(
						'id'          => self::OWN_META,
						/* translators: %s: currency symbol */
						'label'       => sprintf( __( 'eBay price (%s)', 'tcgiant-sync' ), self::currency_symbol() ),
						'value'       => '' !== $own ? self::localized( $own ) : '',
						'placeholder' => self::localized( $rule_says ),
						'data_type'   => 'price',
						'desc_tip'    => true,
						'description' => __( 'What this product sells for on eBay. Leave it empty to send its regular price, under the eBay price rule in Settings if there is one (shown greyed). An import with Overwrite Price on puts eBay\'s price changes here, and leaves the website price alone.', 'tcgiant-sync' ),
					) );
					?>
				<?php endif; ?>
				<p style="margin:4px 0 0;font-size:11px;color:#888;padding-left:12px;">
					<?php echo esc_html( $shown['how'] ); ?>
					<?php if ( $auction ) : ?>
						<br><?php esc_html_e( 'This listing is an auction made on eBay: an Update leaves its opening bid as it is on eBay.', 'tcgiant-sync' ); ?>
					<?php endif; ?>
				</p>
				<?php $too_low = $variable ? null : self::floor_refusal( $product, $shown['price'] ); ?>
				<?php if ( $too_low ) : ?>
					<p style="margin:4px 0 0;font-size:11px;color:#cc1818;padding-left:12px;"><?php echo esc_html( $too_low->get_error_message() ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! $variable ) : ?>
		<script>
		jQuery( function ( $ ) {
			// The Push and Verify buttons save what the tab holds before the
			// product is updated: the eBay price goes with them.
			$.ajaxPrefilter( function ( options ) {
				if ( 'string' !== typeof options.data || ! /(^|&)action=tcgiant_(push|verify)_product(&|$)/.test( options.data ) ) {
					return;
				}
				var field = $( '#<?php echo esc_js( self::OWN_META ); ?>' );
				if ( field.length ) {
					options.data += '&override_ebay_price_shown=1&override_ebay_price=' + encodeURIComponent( field.val() || '' );
				}
			} );
		} );
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * The shop-wide rule and the tax setting, on the Settings screen's push
	 * form.
	 *
	 * @param array $settings The plugin's settings.
	 */
	public static function render_settings_fields( array $settings ) {
		$rule     = (string) ( $settings[ self::RULE_SETTING ] ?? 'none' );
		$rounding = (string) ( $settings[ self::ROUNDING_SETTING ] ?? 'none' );
		$tax      = self::tax_mode( $settings );
		$name     = function ( $key ) {
			return 'tcgiant_sync_ebay_settings[' . $key . ']';
		};
		?>
		<!-- eBay price rule (3.29.0) -->
		<div class="tc-field" id="tc-ebay-price-rule">
			<?php
			// Why the last save left the rule as it was (check_settings()).
			if ( function_exists( 'settings_errors' ) ) {
				settings_errors( 'tcgiant_sync_ebay_settings' );
			}
			?>
			<label class="tc-label" for="<?php echo esc_attr( self::RULE_SETTING ); ?>"><?php esc_html_e( 'eBay Price', 'tcgiant-sync' ); ?></label>
			<select class="tc-input" id="<?php echo esc_attr( self::RULE_SETTING ); ?>" name="<?php echo esc_attr( $name( self::RULE_SETTING ) ); ?>" style="max-width:360px;">
				<option value="none" <?php selected( $rule, 'none' ); ?>><?php esc_html_e( 'The regular price (no rule)', 'tcgiant-sync' ); ?></option>
				<option value="percent" <?php selected( $rule, 'percent' ); ?>><?php esc_html_e( 'The regular price plus a percentage', 'tcgiant-sync' ); ?></option>
				<option value="fixed" <?php selected( $rule, 'fixed' ); ?>><?php esc_html_e( 'The regular price plus a fixed amount', 'tcgiant-sync' ); ?></option>
				<option value="both" <?php selected( $rule, 'both' ); ?>><?php esc_html_e( 'The regular price plus a percentage and a fixed amount', 'tcgiant-sync' ); ?></option>
			</select>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
				<div style="flex:1;min-width:140px;">
					<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;" for="<?php echo esc_attr( self::PERCENT_SETTING ); ?>"><?php esc_html_e( 'Percentage (%)', 'tcgiant-sync' ); ?></label>
					<input type="text" inputmode="decimal" class="tc-input" id="<?php echo esc_attr( self::PERCENT_SETTING ); ?>" name="<?php echo esc_attr( $name( self::PERCENT_SETTING ) ); ?>" value="<?php echo esc_attr( (string) ( $settings[ self::PERCENT_SETTING ] ?? '' ) ); ?>" placeholder="e.g. 12" style="width:100%;">
				</div>
				<div style="flex:1;min-width:140px;">
					<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;" for="<?php echo esc_attr( self::FIXED_SETTING ); ?>"><?php esc_html_e( 'Fixed amount', 'tcgiant-sync' ); ?></label>
					<input type="text" inputmode="decimal" class="tc-input" id="<?php echo esc_attr( self::FIXED_SETTING ); ?>" name="<?php echo esc_attr( $name( self::FIXED_SETTING ) ); ?>" value="<?php echo esc_attr( (string) ( $settings[ self::FIXED_SETTING ] ?? '' ) ); ?>" placeholder="e.g. 0.50" style="width:100%;">
				</div>
				<div style="flex:1;min-width:140px;">
					<label style="font-size:12px;color:#555;display:block;margin-bottom:3px;" for="<?php echo esc_attr( self::ROUNDING_SETTING ); ?>"><?php esc_html_e( 'Rounding', 'tcgiant-sync' ); ?></label>
					<select class="tc-input" id="<?php echo esc_attr( self::ROUNDING_SETTING ); ?>" name="<?php echo esc_attr( $name( self::ROUNDING_SETTING ) ); ?>" style="width:100%;">
						<option value="none" <?php selected( $rounding, 'none' ); ?>><?php esc_html_e( 'None', 'tcgiant-sync' ); ?></option>
						<option value="99" <?php selected( $rounding, '99' ); ?>><?php esc_html_e( 'Up to .99', 'tcgiant-sync' ); ?></option>
						<option value="whole" <?php selected( $rounding, 'whole' ); ?>><?php esc_html_e( 'Up to a whole number', 'tcgiant-sync' ); ?></option>
					</select>
				</div>
			</div>
			<p class="tc-hint"><?php esc_html_e( 'The price every push sends eBay for a product that has no eBay price of its own (set one on its eBay tab, or for each variation under Variations). The percentage comes first, then the amount, then the rounding, which never lowers the price. Both are a markup: zero or more. No rule sends the regular price, as before. A push that would send less than 0.99 from the rule, tax or an eBay price of its own is refused, and says why.', 'tcgiant-sync' ); ?></p>
			<p class="tc-hint"><?php esc_html_e( 'Until TCGiant Sync has sent or read a listing\'s price once with this version, it cannot tell a price change made on eBay from the old price. For a rule you switch on, that keeps the markup from being taken off your website prices. But a price you change on eBay in that time (up to a week, until the weekly full sync reads every listing) is kept off the website price too, and the next push sends the rule\'s price over it. Change prices here, or push first.', 'tcgiant-sync' ); ?></p>
			<p class="tc-hint"><?php esc_html_e( 'With Overwrite Price on, an import never writes the eBay price into the website price: a product with its own eBay price has eBay\'s changes put in that field, and under a rule the website price changes only when eBay\'s price has changed since it was last sent or read, to the price the rule turns into eBay\'s.', 'tcgiant-sync' ); ?></p>
		</div>

		<div class="tc-field" id="tc-ebay-price-tax">
			<label class="tc-label"><?php esc_html_e( 'eBay Prices Include Tax', 'tcgiant-sync' ); ?></label>
			<div class="tc-radio-group">
				<label><input type="radio" name="<?php echo esc_attr( $name( self::TAX_SETTING ) ); ?>" value="no" <?php checked( $tax, 'no' ); ?>> <?php esc_html_e( 'No', 'tcgiant-sync' ); ?></label>
				<label><input type="radio" name="<?php echo esc_attr( $name( self::TAX_SETTING ) ); ?>" value="auto" <?php checked( $tax, 'auto' ); ?>> <?php esc_html_e( 'Auto', 'tcgiant-sync' ); ?></label>
				<label><input type="radio" name="<?php echo esc_attr( $name( self::TAX_SETTING ) ); ?>" value="yes" <?php checked( $tax, 'yes' ); ?>> <?php esc_html_e( 'Yes', 'tcgiant-sync' ); ?></label>
			</div>
			<p class="tc-hint"><?php esc_html_e( 'Buyers on eBay UK, Germany, France, Italy, Spain and Australia see prices with tax in them. If WooCommerce calculates tax and your prices are entered without it, Yes or Auto adds your WooCommerce tax rate (for each product\'s tax class, at your shop\'s address) before the eBay price rule on those six eBay sites, and an import takes it off again. On eBay US and Canada nothing is added, whichever you choose: eBay adds sales tax at checkout there. No sends prices as they are entered, as before. Nothing is added when your prices are entered with tax.', 'tcgiant-sync' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Whether the notice offering Auto is due (3.31.1): once, to a shop that
	 * ran an earlier version (decide_tax_default()), still on No, on eBay UK,
	 * Germany, France, Italy, Spain or Australia, whose WooCommerce
	 * calculates tax with its prices entered without it - the shops Auto
	 * changes. Not after it was answered.
	 *
	 * @param array|null $settings The plugin's settings, or null for the stored ones.
	 * @return bool
	 */
	public static function tax_notice_due( $settings = null ) {
		$record = get_option( self::TAX_DEFAULT_OPTION, array() );
		if ( ! is_array( $record ) || 'pending' !== ( $record['notice'] ?? '' ) ) {
			return false;
		}
		if ( 'no' !== self::tax_mode( $settings ) || ! class_exists( 'TCGiant_Sync_API' ) ) {
			return false;
		}
		$config = TCGiant_Sync_API::instance()->get_marketplace_config();
		if ( ! in_array( (string) ( $config['country'] ?? '' ), self::TAX_COUNTRIES, true ) ) {
			return false;
		}

		return function_exists( 'wc_tax_enabled' ) && wc_tax_enabled()
			&& ! ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() );
	}

	/**
	 * The notice offering Auto (3.31.1), on the WordPress dashboard and
	 * TCGiant Sync's own screens (fix round 1: not the product screens), to
	 * administrators, while it is due (tax_notice_due()). It says what Auto
	 * changes: each eBay price rises by the shop's tax rate at its listing's
	 * next push, update or relist. Answered with Use Auto or Keep No
	 * (handle_tax_notice()); a shop that chose Auto or Yes in Settings
	 * meanwhile has answered, and does not see it.
	 *
	 * @return void
	 */
	public static function render_tax_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'dashboard' !== $screen->id && false === strpos( (string) $screen->id, 'tcgiant' ) ) ) {
			return;
		}

		// Chosen in Settings since: answered, for good.
		$record = get_option( self::TAX_DEFAULT_OPTION, array() );
		if ( is_array( $record ) && 'pending' === ( $record['notice'] ?? '' ) && 'no' !== self::tax_mode() ) {
			$record['notice'] = 'chosen';
			update_option( self::TAX_DEFAULT_OPTION, $record, true );
			return;
		}
		if ( ! self::tax_notice_due() ) {
			return;
		}

		$config = TCGiant_Sync_API::instance()->get_marketplace_config();
		$site   = (string) ( TCGiant_Sync_API::MARKETPLACES[ (string) ( $config['marketplace_id'] ?? '' ) ]['label'] ?? '' );
		$tax    = 'AU' === (string) ( $config['country'] ?? '' ) ? __( 'GST', 'tcgiant-sync' ) : __( 'VAT', 'tcgiant-sync' );
		$rate   = self::base_rate( '' );
		?>
		<div class="notice notice-info" id="tc-tax-notice">
			<p>
				<strong>
					<?php
					/* translators: %s: VAT, or GST in Australia */
					echo esc_html( sprintf( __( 'TCGiant Sync - should your eBay prices include %s?', 'tcgiant-sync' ), $tax ) );
					?>
				</strong><br>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: an eBay site, e.g. United Kingdom, 2: VAT, or GST in Australia */
					__( 'Buyers on eBay %1$s see prices with %2$s in them. Your WooCommerce prices are entered without tax, and "eBay Prices Include Tax" is No, so TCGiant Sync sends eBay your prices as they are entered, without %2$s.', 'tcgiant-sync' ),
					$site,
					$tax
				) );
				?>
			</p>
			<p>
				<?php
				echo esc_html( $rate > 0
					? sprintf(
						/* translators: 1: VAT, or GST in Australia, 2: the standard rate, e.g. 20% */
						__( 'Auto adds your %1$s to your eBay prices: your eBay prices rise by your %1$s rate (%2$s, the standard rate at your shop\'s address; each product\'s own tax class decides its rate) at each listing\'s next Push, Update or relist.', 'tcgiant-sync' ),
						$tax,
						self::percent_words( $rate * 100 ) . '%'
					)
					: sprintf(
						/* translators: 1: VAT, or GST in Australia */
						__( 'Auto adds your %1$s to your eBay prices: your eBay prices rise by your %1$s rate (each product\'s, from its tax class, at your shop\'s address) at each listing\'s next Push, Update or relist.', 'tcgiant-sync' ),
						$tax
					) );
				?>
				<?php esc_html_e( 'A product with an eBay price of its own keeps it, a running auction keeps its opening bid, and an import takes the tax off again before it writes a website price. New installs start on Auto since 3.31.1; this shop stays on No unless you choose Auto.', 'tcgiant-sync' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 10px;">
				<input type="hidden" name="action" value="tcgiant_tax_notice">
				<?php wp_nonce_field( 'tcgiant_tax_notice' ); ?>
				<button type="submit" class="button button-primary" name="tcgiant_tax_choice" value="auto"><?php esc_html_e( 'Use Auto', 'tcgiant-sync' ); ?></button>
				<button type="submit" class="button" name="tcgiant_tax_choice" value="no"><?php esc_html_e( 'Keep No', 'tcgiant-sync' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings#tc-ebay-price-tax' ) ); ?>" style="margin-left:8px;"><?php esc_html_e( 'See the setting', 'tcgiant-sync' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * The notice's answer, posted (3.31.1).
	 *
	 * @return void
	 */
	public static function handle_tax_notice() {
		check_admin_referer( 'tcgiant_tax_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'tcgiant-sync' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		self::apply_tax_choice( isset( $_POST['tcgiant_tax_choice'] ) ? sanitize_key( wp_unslash( $_POST['tcgiant_tax_choice'] ) ) : '' );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=tcgiant-settings' ) );
		exit;
	}

	/**
	 * Save the notice's answer (3.31.1): Auto, or No kept, saved as the
	 * shop's own choice - only that key, onto the settings as they are now
	 * (TCGiant_Sync_Inventory::save_settings_keys()) - and the notice put
	 * away for good. Nothing is pushed: each price changes at its listing's
	 * next push.
	 *
	 * @param string $choice 'auto' or 'no'.
	 * @return bool Whether it was an answer.
	 */
	public static function apply_tax_choice( $choice ) {
		if ( ! in_array( $choice, array( 'auto', 'no' ), true ) ) {
			return false;
		}

		TCGiant_Sync_Inventory::save_settings_keys( array( self::TAX_SETTING => $choice ) );
		$record           = get_option( self::TAX_DEFAULT_OPTION, array() );
		$record           = is_array( $record ) ? $record : array();
		$record['notice'] = 'auto' === $choice ? 'auto' : 'kept';
		update_option( self::TAX_DEFAULT_OPTION, $record, true );

		TCGiant_Sync_Logger::log( 'auto' === $choice
			? '"eBay Prices Include Tax" set to Auto from the notice: each eBay price has the WooCommerce tax rate added at its listing\'s next push.'
			: '"eBay Prices Include Tax" kept at No from the notice: eBay prices go as they are entered, as before.' );

		return true;
	}

	// -------------------------------------------------------------------------
	// Money
	// -------------------------------------------------------------------------

	/**
	 * A sum in whole cents, half a cent up. Floating-point products such as
	 * 11.30 x 1.15 come out a hair under the half cent they are; they are
	 * taken to four places first.
	 *
	 * @param mixed $amount A number.
	 * @return int
	 */
	public static function cents( $amount ) {
		return (int) round( round( (float) $amount * 100, 4 ) );
	}

	/**
	 * Cents as eBay takes a price: two decimal places, a full stop.
	 *
	 * @param int $cents Cents.
	 * @return string
	 */
	public static function money( $cents ) {
		return number_format( (int) $cents / 100, 2, '.', '' );
	}

	/**
	 * Cents rounded up: to the whole number plus 99, or to the next whole
	 * number. A price already there stays.
	 *
	 * @param int    $cents    Cents.
	 * @param string $rounding 'none', '99' or 'whole'.
	 * @return int
	 */
	private static function rounded( $cents, $rounding ) {
		if ( '99' === $rounding ) {
			return intdiv( $cents, 100 ) * 100 + 99;
		}
		if ( 'whole' === $rounding ) {
			return (int) ( ceil( $cents / 100 ) * 100 );
		}
		return $cents;
	}

	/**
	 * A website price with the postage the import adds to it, as the import
	 * has always written one (two decimal places).
	 *
	 * @param string $price    A price.
	 * @param float  $shipping Postage, or 0.
	 * @return string
	 */
	private static function with_postage( $price, $shipping ) {
		return $shipping > 0 ? self::money( self::cents( (float) $price + $shipping ) ) : $price;
	}

	/**
	 * A figure the shop typed, as a number, or null when it is not one it
	 * can be sure of. Spaces go; a currency sign or code before or after an
	 * amount goes ("$1", "1 EUR"), as does a "%" after a percentage; a plus
	 * or minus sign is kept. A comma or a full stop is the decimal point
	 * where it is the last separator with one or two digits after it, or a
	 * full stop on its own ("1.000" is 1): "1,50" is 1.5, "1,000.50" and
	 * "1.000,50" are 1000.5, "1,000" is 1000. Anything else - "ten", "1-2",
	 * "1%" for an amount - is null, never 0.
	 *
	 * @param mixed $raw     What was typed or saved.
	 * @param bool  $percent Whether it is a percentage.
	 * @return float|null
	 */
	public static function parse_figure( $raw, $percent = false ) {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		$text = (string) preg_replace( '/[\s\x{00A0}\x{202F}]+/u', '', (string) $raw );
		$sign = 1.0;
		if ( '' !== $text && ( '-' === $text[0] || '+' === $text[0] ) ) {
			$sign = '-' === $text[0] ? -1.0 : 1.0;
			$text = substr( $text, 1 );
		}
		if ( $percent ) {
			$text = rtrim( $text, '%' );
		} else {
			$text = (string) preg_replace( '/^(?:\p{Sc}|[A-Za-z]{3})|(?:\p{Sc}|[A-Za-z]{3})$/u', '', $text );
		}
		if ( 1 !== preg_match( '/^[0-9.,]*[0-9][0-9.,]*$/', $text ) ) {
			return null;
		}

		$comma = strrpos( $text, ',' );
		$dot   = strrpos( $text, '.' );
		if ( false !== $comma && false !== $dot ) {
			// Both: the last one is the decimal point, the other groups thousands.
			$point = max( $comma, $dot );
			$text  = str_replace( array( ',', '.' ), '', substr( $text, 0, $point ) ) . '.' . substr( $text, $point + 1 );
		} elseif ( false !== $comma ) {
			$after = strlen( $text ) - $comma - 1;
			$text  = ( 1 === substr_count( $text, ',' ) && $after > 0 && $after <= 2 ) ? str_replace( ',', '.', $text ) : str_replace( ',', '', $text );
		} elseif ( false !== $dot && substr_count( $text, '.' ) > 1 ) {
			$text = str_replace( '.', '', $text );
		}

		return is_numeric( $text ) ? $sign * (float) $text : null;
	}

	/**
	 * A percentage as words show it: 10, 12.5, 19.6.
	 *
	 * @param float $percent A percentage.
	 * @return string
	 */
	private static function percent_words( $percent ) {
		return rtrim( rtrim( number_format( (float) $percent, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * The Settings screen's eBay price figures, checked as the push form is
	 * saved (TCGiant_Sync_Admin::sanitize_settings()). The percentage and the
	 * amount are a markup: zero or more, read by parse_figure(), and stored
	 * as plain figures ("10", "1.50"). Only the figures the chosen rule uses
	 * are checked: one of those that is below zero, or that cannot be read,
	 * is refused, and so is a rule missing the figure it needs. The rule,
	 * its figures and its rounding then stay as they were saved before, and
	 * the screen says why (add_settings_error()). A box the rule does not
	 * use keeps what was typed in it, unchecked, so "no rule" always saves.
	 * Never a figure of 0 stored for one that could not be read.
	 *
	 * @param array $settings What the save would store.
	 * @param array $stored   What is stored now.
	 * @return array What to store.
	 */
	public static function check_settings( array $settings, array $stored ) {
		$keys = array( self::RULE_SETTING, self::PERCENT_SETTING, self::FIXED_SETTING, self::ROUNDING_SETTING );
		if ( ! array_intersect_key( $settings, array_flip( $keys ) ) ) {
			return $settings;
		}

		$kind  = (string) ( $settings[ self::RULE_SETTING ] ?? 'none' );
		$kind  = in_array( $kind, array( 'none', 'percent', 'fixed', 'both' ), true ) ? $kind : 'none';
		$wrong = array();
		$clean = array( self::RULE_SETTING => $kind );
		foreach ( array( self::PERCENT_SETTING => true, self::FIXED_SETTING => false ) as $key => $is_percent ) {
			$typed  = trim( (string) ( $settings[ $key ] ?? '' ) );
			$needed = 'both' === $kind || ( $is_percent ? 'percent' : 'fixed' ) === $kind;
			$label  = $is_percent ? __( 'percentage', 'tcgiant-sync' ) : __( 'fixed amount', 'tcgiant-sync' );
			if ( '' === $typed ) {
				if ( $needed ) {
					/* translators: %s: "percentage" or "fixed amount" */
					$wrong[] = sprintf( __( 'the rule needs a %s', 'tcgiant-sync' ), $label );
				}
				$clean[ $key ] = '';
				continue;
			}
			$figure = self::parse_figure( $typed, $is_percent );
			if ( ! $needed && ( null === $figure || $figure < 0 ) ) {
				// A box the chosen rule does not use: kept as typed.
				$clean[ $key ] = $typed;
			} elseif ( null === $figure ) {
				/* translators: 1: what was typed, 2: "percentage" or "fixed amount" */
				$wrong[] = sprintf( __( '"%1$s" is not a %2$s TCGiant Sync can read', 'tcgiant-sync' ), $typed, $label );
			} elseif ( $figure < 0 ) {
				/* translators: 1: what was typed, 2: "percentage" or "fixed amount" */
				$wrong[] = sprintf( __( 'the %2$s must be zero or more, not %1$s: the rule adds to the price', 'tcgiant-sync' ), $typed, $label );
			} else {
				$clean[ $key ] = $is_percent ? self::percent_words( $figure ) : self::money( self::cents( $figure ) );
			}
		}
		$rounding                        = (string) ( $settings[ self::ROUNDING_SETTING ] ?? 'none' );
		$clean[ self::ROUNDING_SETTING ] = in_array( $rounding, array( '99', 'whole' ), true ) ? $rounding : 'none';

		if ( ! $wrong ) {
			return array_replace( $settings, $clean );
		}

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$settings[ $key ] = $stored[ $key ];
			} else {
				unset( $settings[ $key ] );
			}
		}
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'tcgiant_sync_ebay_settings',
				'tcgiant_ebay_price_rule',
				/* translators: %s: what is wrong, e.g. "the fixed amount must be zero or more, not -5" */
				sprintf( __( 'The eBay price rule was not saved, and stays as it was: %s.', 'tcgiant-sync' ), implode( '; ', $wrong ) ),
				'error'
			);
		}

		return $settings;
	}

	/**
	 * The shop's currency symbol, as WooCommerce's own price fields label
	 * themselves with it.
	 *
	 * @return string
	 */
	private static function currency_symbol() {
		return function_exists( 'get_woocommerce_currency_symbol' ) ? (string) get_woocommerce_currency_symbol() : '';
	}

	/**
	 * A price as WooCommerce shows one in a price field: with the shop's
	 * decimal separator.
	 *
	 * @param string $price A price.
	 * @return string
	 */
	private static function localized( $price ) {
		return function_exists( 'wc_format_localized_price' ) ? (string) wc_format_localized_price( $price ) : (string) $price;
	}

	/**
	 * The plugin's settings: those given, or the stored ones.
	 *
	 * @param array|null $settings Settings, or null.
	 * @return array
	 */
	private static function settings( $settings ) {
		if ( is_array( $settings ) ) {
			return $settings;
		}
		$stored = class_exists( 'TCGiant_Sync_OAuth' ) ? TCGiant_Sync_OAuth::instance()->get_settings() : array();
		return is_array( $stored ) ? $stored : array();
	}
}
