<?php
/**
 * WooCommerce's product attributes lookup table (from WooCommerce 6.1), as
 * far as Filterable item specifics (3.35.0) tells it a product changed: the
 * table its filters read when "Use the product attributes lookup table" is
 * on. Played for support/filterable-world.php, which needs it in its own
 * namespace.
 *
 * @package TCGiant_Sync
 */

namespace Automattic\WooCommerce\Internal\ProductAttributesLookup;

/** Each product it was told of, in order, with the changeset it was given. */
class LookupDataStore {
	public $changed = array();
	public function on_product_changed( $product, $changeset = null ) {
		$this->changed[] = array( (int) $product->get_id(), $changeset );
	}
}
