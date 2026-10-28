<?php
/**
 * Harness: what an import is allowed to do to a shop's attributes.
 *
 * A merchant wrote in: "our global attributes are being overwritten with the
 * plugin when we make a sale on eBay... the attribute goes away when the sync
 * is happening... we have almost everything set to no."
 *
 * They were right on every point, including the last one. set_attributes()
 * replaces the whole set, and the list handed to it was built only from eBay's
 * item specifics - so every attribute the shop had set itself was destroyed on
 * each import of an existing product. A sale triggers a delta import, which
 * re-maps the product, which is why it happened on every sale.
 *
 * And there was nothing they could have set differently. Title, description,
 * images, categories and dimensions each have an Overwrite toggle that defaults
 * to WooCommerce winning. Attributes had no toggle at all.
 *
 * The toggle is the smaller half of the fix. The important half is that a
 * global attribute is never touched even when overwriting is turned ON: eBay's
 * item specifics are free text and cannot safely populate a taxonomy term, so
 * the shop's structured data is the better record of the two.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-66s got %-26s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// ---- stand-ins for the WooCommerce pieces the merge touches ---------------------------

class Attr {
	private $name;
	private $options;
	private $position;
	private $taxonomy;

	public function __construct( $name, $options = array(), $position = 0, $taxonomy = false ) {
		$this->name     = $name;
		$this->options  = $options;
		$this->position = $position;
		$this->taxonomy = $taxonomy;
	}

	public function get_name() { return $this->name; }
	public function get_options() { return $this->options; }
	public function get_position() { return $this->position; }
	public function set_position( $p ) { $this->position = $p; }
	public function is_taxonomy() { return $this->taxonomy; }
}

/** WooCommerce turns pa_colour into the label "Colour". */
function wc_attribute_label( $name ) {
	return ucfirst( str_replace( array( 'pa_', '-', '_' ), array( '', ' ', ' ' ), $name ) );
}

/**
 * The merge, verbatim.
 *
 * @param array $existing      key => Attr, as the product already has them.
 * @param array $from_ebay     key => Attr, built from this import's item specifics.
 * @param bool  $is_new        whether the product is being created.
 * @param bool  $overwrite     the Overwrite Attributes setting.
 * @return array|null          what would be written, or null for "left alone".
 */
function merge_attributes( array $existing, array $from_ebay, $is_new, $overwrite ) {
	$merged  = $existing;
	$claimed = array();

	foreach ( $existing as $existing_key => $existing_attribute ) {
		$label = $existing_attribute->is_taxonomy()
			? wc_attribute_label( $existing_attribute->get_name() )
			: $existing_attribute->get_name();

		$claimed[ strtolower( trim( (string) $label ) ) ] = $existing_key;
	}

	foreach ( $from_ebay as $new_key => $new_attribute ) {
		$label = strtolower( trim( (string) $new_attribute->get_name() ) );

		if ( isset( $claimed[ $label ] ) ) {
			$already = $existing[ $claimed[ $label ] ];

			if ( $already->is_taxonomy() ) {
				continue;
			}

			$new_attribute->set_position( (int) $already->get_position() );
			$new_key = $claimed[ $label ];
		}

		$merged[ $new_key ] = $new_attribute;
	}

	if ( $is_new || $overwrite ) {
		return $merged;
	}

	return null;   // nothing is written at all
}

function names_of( $attrs ) {
	if ( null === $attrs ) {
		return null;
	}

	$out = array();
	foreach ( $attrs as $a ) {
		$out[] = $a->get_name();
	}
	sort( $out );

	return $out;
}

// ---- the shop from the report ----------------------------------------------------------
//
// A global Colour attribute and a global Brand attribute set in WooCommerce,
// plus one plain custom one. eBay sends two item specifics.

function gadget_central() {
	return array(
		'pa_colour' => new Attr( 'pa_colour', array( 'Black' ), 0, true ),
		'pa_brand'  => new Attr( 'pa_brand', array( 'Acme' ), 1, true ),
		'warranty'  => new Attr( 'Warranty', array( '2 years' ), 2, false ),
	);
}

function from_ebay() {
	return array(
		'colour'   => new Attr( 'Colour', array( 'Jet Black' ), 3, false ),
		'material' => new Attr( 'Material', array( 'Aluminium' ), 4, false ),
	);
}

echo "\nWHAT A SALE USED TO DO\n" . str_repeat( '=', 112 ) . "\n";

// The old behaviour, for contrast: the whole set replaced by eBay's two.
$old = names_of( array_values( from_ebay() ) );
check( 'before: everything the shop set was gone', $old, array( 'Colour', 'Material' ) );
check( '  including both global attributes', in_array( 'pa_colour', $old, true ), false );

echo "\nWITH THE SETTING AS THEY HAD IT (NO)\n" . str_repeat( '=', 112 ) . "\n";

$result = merge_attributes( gadget_central(), from_ebay(), false, false );
check( 'an existing product is not touched at all', $result, null );

echo "\nAND EVEN WITH IT TURNED ON\n" . str_repeat( '=', 112 ) . "\n";

$result = merge_attributes( gadget_central(), from_ebay(), false, true );

check( 'both global attributes survive', names_of( $result ), array( 'Material', 'Warranty', 'pa_brand', 'pa_colour' ) );

// eBay's "Colour" collides with the global pa_colour. The shop's structured
// one wins, and eBay's is dropped rather than added beside it - two rows both
// called Colour is worse than either.
check( 'the global Colour keeps its own value', $result['pa_colour']->get_options(), array( 'Black' ) );
check( '  and no second Colour is added', count( $result ), 4 );

// A custom attribute eBay says nothing about is left exactly as it was.
check( 'an untouched custom attribute survives', $result['warranty']->get_options(), array( '2 years' ) );

// And one eBay does supply is genuinely updated.
check( 'a new specific from eBay is added', isset( $result['material'] ), true );
check( '  with eBay\'s value', $result['material']->get_options(), array( 'Aluminium' ) );

echo "\nA CUSTOM ATTRIBUTE EBAY ALSO KNOWS ABOUT\n" . str_repeat( '=', 112 ) . "\n";

// No taxonomy involved: here eBay should win, and in place rather than appended.
$existing = array(
	'warranty' => new Attr( 'Warranty', array( '1 year' ), 0, false ),
	'weight'   => new Attr( 'Weight', array( '2kg' ), 1, false ),
);
$ebay = array( 'warranty' => new Attr( 'Warranty', array( '3 years' ), 9, false ) );

$result = merge_attributes( $existing, $ebay, false, true );

check( 'eBay updates a plain custom attribute', $result['warranty']->get_options(), array( '3 years' ) );
check( '  keeping the place it already had', $result['warranty']->get_position(), 0 );
check( '  and the other one is untouched', $result['weight']->get_options(), array( '2kg' ) );
check( '  with nothing duplicated', count( $result ), 2 );

echo "\nA BRAND NEW PRODUCT STILL TAKES EVERYTHING FROM EBAY\n" . str_repeat( '=', 112 ) . "\n";

// Nothing to protect on a product being created, so the setting does not apply.
$result = merge_attributes( array(), from_ebay(), true, false );
check( 'a new product gets eBay\'s specifics', names_of( $result ), array( 'Colour', 'Material' ) );

echo "\nWHAT THE SOURCE MUST KEEP DOING\n" . str_repeat( '=', 112 ) . "\n";

$root     = dirname( __DIR__ );
$mapper   = (string) file_get_contents( $root . '/includes/class-tcgiant-sync-mapper.php' );
$settings = (string) file_get_contents( $root . '/admin/views/settings.php' );

check( 'the existing set is read before anything is built', strpos( $mapper, '$existing_attributes = $product->get_attributes();' ) < strpos( $mapper, '$merged = $existing_attributes;' ), true );
check( 'the write starts from what was already there', false !== strpos( $mapper, '$merged = $existing_attributes;' ), true );
check( '  and a taxonomy attribute is skipped, not replaced', false !== strpos( $mapper, 'if ( $already->is_taxonomy() ) {' ), true );
check( '  nothing is written straight from eBay any more', false === strpos( $mapper, 'set_attributes( array_values( $woo_attributes ) )' ), true );

check( 'the write is gated like every other field', false !== strpos( $mapper, 'if ( $is_new || $overwrite_attributes ) {' ), true );
check( '  and says which way it went', false !== strpos( $mapper, "'Attributes skipped (WooCommerce won)'" ), true );

check( 'the setting exists', false !== strpos( $settings, "'overwrite_attributes'  => array( 'label' => __( 'Overwrite Attributes', 'tcgiant-sync' ), 'default' => '0' )," ), true );
check( '  defaulting to WooCommerce winning', false !== strpos( $settings, "__( 'Overwrite Attributes', 'tcgiant-sync' ), 'default' => '0'" ), true );
// 3.28.0: a save keeps every setting but its own form's (TCGiant_Sync_Admin::SETTINGS_FORMS),
// so the setting survives by being one of Import Settings' own.
$admin_src = (string) file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' );
$forms_at  = (int) strpos( $admin_src, 'const SETTINGS_FORMS' );
$import_of = substr( $admin_src, $forms_at, (int) strpos( $admin_src, "'export' => array(", $forms_at ) - $forms_at );
check( '  and surviving a save', array( $forms_at > 0, false !== strpos( $import_of, "'overwrite_attributes'" ) ), array( true, true ) );
check( '  with the screen saying globals are never touched', false !== strpos( $settings, 'Global attributes you have set in WooCommerce are never overwritten' ), true );

// Variations are deliberately left replacing. A variation's attributes are its
// identity - they are also the signature used to match it to eBay's - so there
// is nothing of the shop's own in there to preserve.
check( 'variations still take their attributes whole', false !== strpos( $mapper, '$variation->set_attributes( $var_attrs );' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
