<?php
/**
 * A stock buffer for eBay (3.45.0): "Keep back from eBay" and "Show at most
 * on eBay".
 *
 * A shop that sells the same stock elsewhere - a counter, a market stall,
 * another marketplace - wants eBay to show less than it holds. Two shop
 * settings, both empty unless the shop fills them in, and the same two on a
 * product's eBay tab:
 *
 * - Keep back from eBay (N): eBay is sent the stock less N, never below 0.
 * - Show at most on eBay (M): eBay is sent no more than M.
 * - Both: min( stock - N, M ), never below 0.
 *
 * Empty or 0 is none. On a product, empty is the shop's setting and 0 none
 * for that product. A variation is sent under its product's: each variation
 * keeping its own stock is sent its stock less N, and a stock its variations
 * share is buffered once, and each of them is sent that figure.
 *
 * With neither set, figure() gives back the stock exactly as it was given,
 * so every push, Update, relist and Send stock to eBay is the request it was
 * before 3.45.0.
 *
 * It applies to every quantity this plugin sends eBay from a stock
 * WooCommerce tracks: a stock push (a stock change or a sale), Send stock to
 * eBay, a new listing, an Update and a relist. Never an auction, which is
 * one item, and never a quantity sent for a product whose stock is not
 * tracked ("Quantity When Stock Is Not Tracked" is the shop's own figure).
 *
 * The other way, eBay's figure is then not the stock: an import, order
 * sync's settle, an ended listing's settle and the weekly check read it as
 * eBay's sales only (TCGiant_Sync_Inventory::stock_beside_buffer()), so the
 * stock is never brought down to the buffered figure.
 *
 * Changing either sends nothing by itself: each listing takes the new
 * figure at its next stock change, or at once with Send stock to eBay.
 * Saving a product's own from its eBay tab queues that product's stock
 * push, as a stock edit would.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Stock_Buffer class.
 */
class TCGiant_Sync_Stock_Buffer {

	/** The shop's "Keep back from eBay" (Settings, with the stock settings). */
	const KEEP_SETTING = 'stock_keep_back';

	/** The shop's "Show at most on eBay". */
	const CAP_SETTING = 'stock_show_at_most';

	/** A product's own "Keep back from eBay": none stored is the shop's, '0' none. */
	const KEEP_META = '_ebay_export_stock_keep_back';

	/** A product's own "Show at most on eBay". */
	const CAP_META = '_ebay_export_stock_show_at_most';

	/**
	 * The buffer is on every plan (3.45.0): the one switch, read by the one
	 * check (available()). Set false, it is Pro's alone.
	 *
	 * @var bool
	 */
	private static $every_plan = true;

	/** The largest figure taken: nine digits. */
	const MAX_DIGITS = 9;

	/**
	 * Whether this site may use the buffer.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( self::$every_plan ) {
			return true;
		}
		return class_exists( 'TCGiant_Sync_Entitlements' ) && TCGiant_Sync_Entitlements::instance()->is_pro();
	}

	/**
	 * One of TCGiant_Sync_Inventory's static methods, callable, or null
	 * where the inventory is not loaded as it ships (a harness's stand-in).
	 *
	 * @param string $method The method.
	 * @return callable|null
	 */
	private static function inventory( $method ) {
		$call = array( 'TCGiant_Sync_Inventory', (string) $method );
		return is_callable( $call ) ? $call : null;
	}

	/**
	 * A figure as typed, kept as a whole number of 0 or more, or '' for none:
	 * empty, or not a whole number.
	 *
	 * @param mixed $raw What was typed or stored.
	 * @return string
	 */
	public static function typed( $raw ) {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		return 1 === preg_match( '/^\d{1,' . self::MAX_DIGITS . '}$/', $raw ) ? (string) (int) $raw : '';
	}

	/**
	 * The shop's two figures, 0 for none.
	 *
	 * @param array|null $settings The settings, or null for the stored ones.
	 * @return int[] keep, cap.
	 */
	public static function shop_limits( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : get_option( 'tcgiant_sync_ebay_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			(int) self::typed( $settings[ self::KEEP_SETTING ] ?? '' ),
			(int) self::typed( $settings[ self::CAP_SETTING ] ?? '' ),
		);
	}

	/**
	 * The product whose eBay tab holds a product's own figures: a
	 * variation's product, else itself.
	 *
	 * @param object $product The product or variation.
	 * @return int
	 */
	private static function owner_id( $product ) {
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			return (int) $product->get_parent_id();
		}
		return method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
	}

	/**
	 * The figures a product's stock is sent under: its own (its eBay tab's,
	 * a variation its product's), else the shop's. 0 for none.
	 *
	 * @param object|int $product The product or variation, or its ID.
	 * @return int[] keep, cap.
	 */
	public static function limits( $product ) {
		if ( ! self::available() ) {
			return array( 0, 0 );
		}
		$product = is_object( $product ) ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product ) : null );
		$shop    = self::shop_limits();
		if ( ! is_object( $product ) ) {
			return $shop;
		}

		$owner = self::owner_id( $product );
		$out   = array();
		foreach ( array( self::KEEP_META, self::CAP_META ) as $i => $meta ) {
			$own   = $owner > 0 ? self::typed( get_post_meta( $owner, $meta, true ) ) : '';
			$out[] = '' !== $own ? (int) $own : (int) $shop[ $i ];
		}

		return $out;
	}

	/**
	 * Whether eBay is sent less than a product's stock, by either figure.
	 *
	 * @param object|int $product The product or variation.
	 * @return bool
	 */
	public static function applies( $product ) {
		list( $keep, $cap ) = self::limits( $product );
		return $keep > 0 || $cap > 0;
	}

	/**
	 * What eBay is sent of this stock: the stock less "Keep back from eBay",
	 * no more than "Show at most on eBay", never below 0. With neither, the
	 * stock exactly as it was given.
	 *
	 * @param object|int $product The product or variation the stock is sent for.
	 * @param int|mixed  $stock   Its stock (a shared stock for a variation taking it).
	 * @return int|mixed
	 */
	public static function figure( $product, $stock ) {
		list( $keep, $cap ) = self::limits( $product );
		if ( 0 === $keep && 0 === $cap ) {
			return $stock;
		}

		$figure = (int) $stock - $keep;
		if ( $cap > 0 ) {
			$figure = min( $figure, $cap );
		}

		return max( 0, $figure );
	}

	/**
	 * For the log, where eBay is sent less than the stock: " - its stock is
	 * 10; Keep back from eBay and Show at most on eBay leave 8 for eBay".
	 * '' otherwise.
	 *
	 * @param int $stock  The stock.
	 * @param int $figure What eBay is sent.
	 * @return string
	 */
	public static function words( $stock, $figure ) {
		if ( (int) $figure >= (int) $stock ) {
			return '';
		}
		return sprintf( ' (its stock is %1$d: "Keep back from eBay" and "Show at most on eBay" leave %2$d for eBay)', (int) $stock, (int) $figure );
	}

	// -------------------------------------------------------------------------
	// The inventory's reading of eBay's figure, for the import and the push
	// -------------------------------------------------------------------------

	/**
	 * TCGiant_Sync_Inventory::stock_beside_buffer(): the stock eBay's figure
	 * leaves a product at, or null when eBay's figure is the stock - and null
	 * where the inventory is not loaded.
	 *
	 * @param object|int $product   The product whose stock it is.
	 * @param string     $item_id   The listing read.
	 * @param string     $sku       eBay's variation SKU, or ''.
	 * @param int        $available What eBay has available.
	 * @param int|null   $sold      eBay's QuantitySold.
	 * @return int|null
	 */
	public static function stock_beside( $product, $item_id, $sku, $available, $sold ) {
		$call = self::inventory( 'stock_beside_buffer' );
		return null !== $call ? call_user_func( $call, $product, $item_id, $sku, $available, $sold ) : null;
	}

	/**
	 * TCGiant_Sync_Inventory::buffered_on(): whether eBay was last sent less
	 * than this product's stock on this listing.
	 *
	 * @param int    $holder_id The product whose stock it is.
	 * @param string $item_id   The listing.
	 * @return bool
	 */
	public static function marked( $holder_id, $item_id ) {
		$call = self::inventory( 'buffered_on' );
		return null !== $call && (bool) call_user_func( $call, $holder_id, $item_id );
	}

	/**
	 * TCGiant_Sync_Inventory::note_landed(): a figure eBay shows of a stock
	 * (fix round 1, review 1's M1: the link of a listing a push made).
	 *
	 * @param object|int $holder  The product whose stock it is.
	 * @param string     $item_id The listing.
	 * @param int        $figure  What eBay shows.
	 * @param int        $stock   The stock.
	 * @return void
	 */
	public static function landed( $holder, $item_id, $figure, $stock ) {
		$call = self::inventory( 'note_landed' );
		if ( null !== $call ) {
			call_user_func( $call, $holder, $item_id, $figure, $stock );
		}
	}

	/**
	 * Whether a figure is one typed() refuses for being too long: a whole
	 * number of more than MAX_DIGITS digits (fix round 1, review 1's L6).
	 *
	 * @param mixed $raw What was typed.
	 * @return bool
	 */
	public static function too_long( $raw ) {
		return is_scalar( $raw ) && 1 === preg_match( '/^\d{' . ( self::MAX_DIGITS + 1 ) . ',}$/', trim( (string) $raw ) );
	}

	/**
	 * Why a figure typed is not kept, in words the settings and the bulk
	 * edit share (fix round 1, review 1's L6).
	 *
	 * @param string $typed What was typed.
	 * @return string
	 */
	public static function refused_words( $typed ) {
		return self::too_long( $typed )
			/* translators: 1: what was typed, 2: the largest figure */
			? sprintf( __( '"%1$s" is more than %2$s, the most it takes', 'tcgiant-sync' ), $typed, str_repeat( '9', self::MAX_DIGITS ) )
			/* translators: %s: what was typed */
			: sprintf( __( '"%s" is not a whole number of 0 or more', 'tcgiant-sync' ), $typed );
	}

	/**
	 * TCGiant_Sync_Inventory::note_listing_sent(): what a new listing, an
	 * Update or a relist sent of each stock.
	 *
	 * @param string $item_id The listing.
	 * @param string $action  created, updated or relisted.
	 * @param array  $sent    Each stock sent.
	 * @return int The stock pushes it queued to start eBay's record of
	 *             sales (fix round 2), 0 with none or no Inventory.
	 */
	public static function note_sent( $item_id, $action, array $sent ) {
		$call = self::inventory( 'note_listing_sent' );
		return $sent && null !== $call ? (int) call_user_func( $call, $item_id, $action, $sent ) : 0;
	}

	// -------------------------------------------------------------------------
	// A product's own, on its eBay tab
	// -------------------------------------------------------------------------

	/**
	 * The product screen's save.
	 */
	public static function register_admin() {
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ) );
	}

	/**
	 * woocommerce_process_product_meta: the eBay tab's two figures saved; a
	 * change is a stock change of the product, and queues its push.
	 *
	 * @param int $post_id The product.
	 */
	public static function save_product_field( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked its own nonce before this action.
		if ( self::save_posted( (int) $post_id, $_POST ) && class_exists( 'TCGiant_Sync_Inventory' ) ) {
			TCGiant_Sync_Inventory::buffer_changed( (int) $post_id );
		}
	}

	/**
	 * A product's own two figures from what was posted, in the tab's names:
	 * the product screen's save and the bulk edit (3.39.0's,
	 * TCGiant_Sync_Bulk_Edit). Only when the tab's fields were posted. A
	 * figure that is not a whole number of 0 or more is left as it was.
	 *
	 * @param int   $product_id The product.
	 * @param array $posted     What was posted, still slashed.
	 * @return bool Whether either changed.
	 */
	public static function save_posted( $product_id, array $posted ) {
		if ( ! isset( $posted[ self::KEEP_META . '_shown' ] ) ) {
			return false;
		}
		$changed = false;
		foreach ( array( self::KEEP_META, self::CAP_META ) as $meta ) {
			if ( ! isset( $posted[ $meta ] ) || ! is_string( $posted[ $meta ] ) ) {
				continue;
			}
			$raw   = trim( sanitize_text_field( wp_unslash( $posted[ $meta ] ) ) );
			$value = self::typed( $raw );
			if ( '' !== $raw && '' === $value ) {
				continue;
			}
			if ( (string) get_post_meta( (int) $product_id, $meta, true ) === $value ) {
				continue;
			}
			if ( '' === $value ) {
				delete_post_meta( (int) $product_id, $meta );
			} else {
				update_post_meta( (int) $product_id, $meta, $value );
			}
			$changed = true;
		}
		return $changed;
	}

	/**
	 * The "Stock on eBay" section of a product's eBay tab.
	 *
	 * @param WC_Product $product The product.
	 */
	public static function render_product_section( $product ) {
		$product_id = (int) $product->get_id();
		$own        = array(
			self::KEEP_META => self::typed( get_post_meta( $product_id, self::KEEP_META, true ) ),
			self::CAP_META  => self::typed( get_post_meta( $product_id, self::CAP_META, true ) ),
		);
		list( $shop_keep, $shop_cap ) = self::shop_limits();
		list( $keep, $cap )           = self::limits( $product );
		$variable = $product->is_type( 'variable' );
		$summary  = ( $keep > 0 || $cap > 0 )
			/* translators: 1: kept back, 2: at most ("none" when not set) */
			? sprintf( __( 'Kept back %1$d, at most %2$s', 'tcgiant-sync' ), $keep, $cap > 0 ? (string) $cap : __( 'none', 'tcgiant-sync' ) )
			: __( 'All of the stock', 'tcgiant-sync' );
		$hint     = 'display:block;margin-top:2px;font-size:11px;color:#888;';
		?>
		<div class="tc-ebay-section" id="tc-section-stock-buffer">
			<div class="tc-ebay-section-head" data-target="tc-body-stock-buffer">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<span><?php esc_html_e( 'Stock on eBay', 'tcgiant-sync' ); ?></span>
				<span id="tc-stock-buffer-summary" style="font-weight:400;color:#888;font-size:12px;margin-left:auto;"><?php echo esc_html( $summary ); ?></span>
			</div>
			<div class="tc-ebay-section-body" id="tc-body-stock-buffer">
				<input type="hidden" name="<?php echo esc_attr( self::KEEP_META . '_shown' ); ?>" value="1">
				<p style="margin:0 0 6px;font-size:12px;color:#555;"><?php echo esc_html( $variable
					? __( 'Each variation keeping its own stock is sent what these leave of its stock; a stock the variations share is buffered once, and each variation is sent that.', 'tcgiant-sync' )
					: __( 'For a product whose stock WooCommerce tracks. Never an auction, which is one item.', 'tcgiant-sync' ) ); ?></p>
				<p class="form-field">
					<label for="<?php echo esc_attr( self::KEEP_META ); ?>"><?php esc_html_e( 'Keep back from eBay', 'tcgiant-sync' ); ?></label>
					<input type="number" min="0" step="1" class="short" style="max-width:120px;" id="<?php echo esc_attr( self::KEEP_META ); ?>" name="<?php echo esc_attr( self::KEEP_META ); ?>" value="<?php echo esc_attr( $own[ self::KEEP_META ] ); ?>" placeholder="<?php echo esc_attr( $shop_keep > 0 ? (string) $shop_keep : '' ); ?>">
					<span style="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html( $shop_keep > 0
						/* translators: %d: the shop's figure */
						? sprintf( __( 'eBay is sent the stock less this, never below 0. Empty: the shop\'s, %d (Settings). 0: none for this product.', 'tcgiant-sync' ), $shop_keep )
						: __( 'eBay is sent the stock less this, never below 0. Empty: the shop\'s, none set (Settings). 0: none for this product.', 'tcgiant-sync' ) ); ?></span>
				</p>
				<p class="form-field">
					<label for="<?php echo esc_attr( self::CAP_META ); ?>"><?php esc_html_e( 'Show at most on eBay', 'tcgiant-sync' ); ?></label>
					<input type="number" min="0" step="1" class="short" style="max-width:120px;" id="<?php echo esc_attr( self::CAP_META ); ?>" name="<?php echo esc_attr( self::CAP_META ); ?>" value="<?php echo esc_attr( $own[ self::CAP_META ] ); ?>" placeholder="<?php echo esc_attr( $shop_cap > 0 ? (string) $shop_cap : '' ); ?>">
					<span style="<?php echo esc_attr( $hint ); ?>"><?php echo esc_html( $shop_cap > 0
						/* translators: %d: the shop's figure */
						? sprintf( __( 'eBay is sent no more than this. Empty: the shop\'s, %d (Settings). 0: none for this product.', 'tcgiant-sync' ), $shop_cap )
						: __( 'eBay is sent no more than this. Empty: the shop\'s, none set (Settings). 0: none for this product.', 'tcgiant-sync' ) ); ?></span>
				</p>
				<p style="margin:4px 0 0;font-size:11px;color:#888;padding-left:12px;"><?php esc_html_e( 'If this leaves 0 for eBay, the listing is treated as out of stock, as "Auto-end listing when stock reaches 0" says. After each eBay sale under "Show at most on eBay", eBay is topped up again - but with a cap of 1 and Out-of-Stock control off, eBay ends the listing at the sale. Saving a change here sends this product\'s stock to eBay, as a stock change would. eBay\'s quantity is then not your stock: an import or a sync takes only eBay\'s own sales off it.', 'tcgiant-sync' ); ?></p>
			</div>
		</div>
		<?php
	}
}
