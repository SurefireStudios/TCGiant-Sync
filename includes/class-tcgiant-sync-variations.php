<?php
/**
 * Pairs an eBay listing's variations with a variable product's.
 *
 * A variation listing sells each variation separately: eBay counts what each
 * one listed and sold, and WooCommerce sells each variation from its own
 * stock. The plugin used to settle such a listing on the parent alone, from
 * the whole listing's figures, so a variation eBay had sold out stayed in
 * stock here. Every place that brings eBay's figures in - the sale check, the
 * ended-listing settle, the hourly check, Stock Review and a relist - pairs
 * the variations through this one class, so they cannot disagree.
 *
 * @package TCGiant_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Variations class
 */
class TCGiant_Sync_Variations {

	/**
	 * eBay's variations, from GetItem's Item.
	 *
	 * eBay's Variation.Quantity is the variation's total - what is left plus
	 * what sold - so what is available is that minus its QuantitySold.
	 *
	 * @param array $item The listing, as GetItem returns it.
	 * @return array[] Per variation: sku ('' when eBay has none), specifics
	 *                 (Name => Value), listed and available (null when eBay
	 *                 did not say), sold, and start_price ('' when absent).
	 */
	public static function from_item( array $item ) {
		$list = $item['Variations']['Variation'] ?? array();
		if ( ! is_array( $list ) || ! $list ) {
			return array();
		}

		// One variation arrives as the node itself, not a list of one.
		if ( ! isset( $list[0] ) ) {
			$list = array( $list );
		}

		$out = array();
		foreach ( $list as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}

			$specifics = array();
			$nvl       = $v['VariationSpecifics']['NameValueList'] ?? array();
			if ( isset( $nvl['Name'] ) ) {
				$nvl = array( $nvl );
			}
			foreach ( (array) $nvl as $pair ) {
				if ( ! is_array( $pair ) ) {
					continue;
				}
				$name  = self::scalar( $pair['Name'] ?? '' );
				$value = $pair['Value'] ?? '';
				// Several values, joined as the mapper joins them.
				$value = is_array( $value ) && ! self::is_text_node( $value )
					? implode( ', ', array_map( array( __CLASS__, 'scalar' ), $value ) )
					: self::scalar( $value );
				if ( '' !== $name ) {
					$specifics[ $name ] = $value;
				}
			}

			$listed = self::scalar( $v['Quantity'] ?? '' );
			$sold   = self::scalar( $v['SellingStatus']['QuantitySold'] ?? '0' );

			$out[] = array(
				'sku'         => self::scalar( $v['SKU'] ?? '' ),
				'specifics'   => $specifics,
				'listed'      => is_numeric( $listed ) ? (int) $listed : null,
				'sold'        => is_numeric( $sold ) ? (int) $sold : 0,
				'available'   => is_numeric( $listed ) ? max( 0, (int) $listed - ( is_numeric( $sold ) ? (int) $sold : 0 ) ) : null,
				'start_price' => self::scalar( $v['StartPrice'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Name/value pairs as a key both sides can make: by label, never by meta
	 * key, so 'pa_colour' and 'Colour' compare alike. The same normalising as
	 * the import's own variation matching.
	 *
	 * @param array $specifics Label => value.
	 * @return string '' when it identifies nothing - an "Any ..." value.
	 */
	public static function signature( array $specifics ) {
		$n = array();
		foreach ( $specifics as $name => $value ) {
			$key = sanitize_title( (string) $name );
			$val = strtolower( trim( (string) $value ) );
			if ( '' === $key || '' === $val ) {
				return '';
			}
			$n[ $key ] = $val;
		}

		if ( ! $n ) {
			return '';
		}

		ksort( $n );

		$parts = array();
		foreach ( $n as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}

		return implode( '|', $parts );
	}

	/**
	 * A WooCommerce variation's attributes, as label => value.
	 *
	 * What eBay called them when this variation was imported, if recorded;
	 * otherwise read from the variation, a global attribute by its label and
	 * term name rather than its pa_ slug.
	 *
	 * @param WC_Product $variation Variation.
	 * @return array
	 */
	public static function wc_specifics( $variation ) {
		$recorded = $variation->get_meta( '_ebay_variation_specifics' );
		if ( is_array( $recorded ) && $recorded ) {
			return $recorded;
		}

		$out = array();
		foreach ( (array) $variation->get_attributes() as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'pa_' ) && taxonomy_exists( $key ) ) {
				$term = '' !== (string) $value ? get_term_by( 'slug', $value, $key ) : false;
				$out[ wc_attribute_label( $key ) ] = $term ? $term->name : (string) $value;
			} else {
				$out[ $key ] = (string) $value;
			}
		}

		return $out;
	}

	/**
	 * Pair eBay's variations with a variable product's.
	 *
	 * In order: the eBay SKU recorded at import, the variation's own SKU, that
	 * SKU as the import makes it unique (SKU-parentID), then the attribute
	 * combination by label. Each WooCommerce variation answers for one eBay
	 * variation at most. A published variation is preferred to one the import
	 * retired; published duplicates of one combination all answer for it.
	 *
	 * @param WC_Product $parent Variable product.
	 * @param array[]    $ebay   From from_item().
	 * @return array pairs (eBay index => variation IDs), unmatched (indexes).
	 */
	public static function match( $parent, array $ebay ) {
		$by_ebay_sku = array();
		$by_wc_sku   = array();
		$by_sig      = array();
		$live        = array();

		foreach ( (array) $parent->get_children() as $id ) {
			$child = wc_get_product( $id );
			if ( ! $child ) {
				continue;
			}

			$id          = (int) $id;
			$live[ $id ] = 'publish' === $child->get_status();

			$recorded = (string) $child->get_meta( '_ebay_variation_sku' );
			if ( '' !== $recorded ) {
				$by_ebay_sku[ $recorded ][] = $id;
			}

			$own = (string) $child->get_sku( 'edit' );
			if ( '' !== $own ) {
				$by_wc_sku[ $own ][] = $id;
			}

			$sig = self::signature( self::wc_specifics( $child ) );
			if ( '' !== $sig ) {
				$by_sig[ $sig ][] = $id;
			}
		}

		$claimed   = array();
		$pairs     = array();
		$unmatched = array();

		foreach ( $ebay as $i => $ev ) {
			$ids = array();
			if ( '' !== $ev['sku'] ) {
				$ids = $by_ebay_sku[ $ev['sku'] ]
					?? $by_wc_sku[ $ev['sku'] ]
					?? $by_wc_sku[ $ev['sku'] . '-' . $parent->get_id() ]
					?? array();
			}
			$sig  = self::signature( $ev['specifics'] );
			$same = '' !== $sig ? ( $by_sig[ $sig ] ?? array() ) : array();
			if ( ! $ids ) {
				$ids = $same;
			} elseif ( ! array_filter( array_diff( $ids, $claimed ), function ( $id ) use ( $live ) {
				return ! empty( $live[ $id ] );
			} ) ) {
				// The SKU names only a variation taken off the website: one
				// published with the same options is the one selling it.
				$ids = array_merge( $ids, array_diff( $same, $ids ) );
			}

			$ids = array_values( array_diff( $ids, $claimed ) );
			if ( ! $ids ) {
				$unmatched[] = $i;
				continue;
			}

			$published = array_values( array_filter( $ids, function ( $id ) use ( $live ) {
				return ! empty( $live[ $id ] );
			} ) );
			$ids       = $published ? $published : array( $ids[0] );

			$pairs[ $i ] = $ids;
			$claimed     = array_merge( $claimed, $ids );
		}

		return array(
			'pairs'     => $pairs,
			'unmatched' => $unmatched,
		);
	}

	/**
	 * eBay's NameValueList for one variation, as XML, verbatim: a relist or a
	 * revise names a variation by these, and they cannot be changed.
	 *
	 * @param array $specifics Label => value.
	 * @return string
	 */
	public static function specifics_xml( array $specifics ) {
		$xml = '<VariationSpecifics>';
		foreach ( $specifics as $name => $value ) {
			$xml .= '<NameValueList><Name>' . esc_xml( (string) $name ) . '</Name><Value>' . esc_xml( (string) $value ) . '</Value></NameValueList>';
		}

		return $xml . '</VariationSpecifics>';
	}

	/**
	 * Whether a decoded value is one text node - a value with attributes -
	 * rather than a list of values.
	 *
	 * @param array $raw Decoded value.
	 * @return bool
	 */
	private static function is_text_node( array $raw ) {
		foreach ( array( '#text', '__text', 'value', 0, '0' ) as $k ) {
			if ( isset( $raw[ $k ] ) && ! is_array( $raw[ $k ] ) && 1 === count( $raw ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A decoded XML value as a string: a price arrives as its text plus the
	 * currency attribute. Kept here rather than borrowed from the API class,
	 * so harnesses that stand in for that class still load this one.
	 *
	 * @param mixed $raw Decoded value.
	 * @return string
	 */
	public static function scalar( $raw ) {
		if ( is_string( $raw ) || is_numeric( $raw ) ) {
			return trim( (string) $raw );
		}

		if ( is_array( $raw ) ) {
			foreach ( array( '#text', '__text', 'value', 0, '0' ) as $key ) {
				if ( isset( $raw[ $key ] ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) {
					return trim( (string) $raw[ $key ] );
				}
			}
		}

		return '';
	}
}
