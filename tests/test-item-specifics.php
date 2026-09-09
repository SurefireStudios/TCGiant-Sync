<?php
/**
 * Harness: the item specifics panel - what eBay requires, and what we would send.
 *
 * A merchant selling networking equipment was told by eBay that "the item
 * specific Type is missing" and wrote back: "Not sure where I would find what
 * type is missing, don't see that in the settings or product when editing the
 * product." They were right. The plugin asks eBay which specifics a category
 * requires, and had done for months - to fail a push with, and nowhere else.
 *
 * What is asserted here:
 *   1. What is typed on the product beats a product attribute of the same name,
 *      including when the two are spelled differently but mean the same aspect.
 *   2. A value's provenance is reported, so the panel can say WHERE each answer
 *      came from rather than leaving someone guessing why a box they never
 *      filled in is satisfied.
 *   3. A failed lookup is its own status. The push treats "eBay did not answer"
 *      as "nothing required", deliberately, so an outage cannot stop anyone
 *      listing - but a panel doing that would report all-clear at the one moment
 *      it cannot know, which is worse than saying nothing.
 *   4. The array-shaped save is safe. Every other save path in the plugin hands
 *      its value to sanitize_text_field(), which returns '' for an array - so
 *      the naive version of this feature would have silently stored nothing.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-64s got %-34s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

// ---- WordPress, stubbed ------------------------------------------------------------

function __( $text, $domain = null ) { return $text; }

class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

/** WordPress returns '' when sanitize_text_field() is handed an array. That is the trap. */
function sanitize_text_field( $value ) {
	if ( is_array( $value ) ) { return ''; }
	return trim( strip_tags( (string) $value ) );
}
function wp_unslash( $value ) {
	if ( is_array( $value ) ) { return array_map( 'wp_unslash', $value ); }
	return stripslashes( (string) $value );
}

// ---- WooCommerce, reduced to what the pipeline touches -------------------------------

class WC_Product_Attribute {
	private $name, $options, $variation;
	public function __construct( $name, $options, $variation = false ) {
		$this->name = $name; $this->options = (array) $options; $this->variation = $variation;
	}
	public function get_name() { return $this->name; }
	public function get_options() { return $this->options; }
	public function get_variation() { return $this->variation; }
	public function is_taxonomy() { return false; }
}

class WC_Product {
	public $attributes = array();
	public function __construct( array $attributes = array() ) { $this->attributes = $attributes; }
	public function get_id() { return 7; }
	public function get_attributes() { return $this->attributes; }
}

function wc_attribute_label( $name, $product = null ) { return $name; }

// ---- eBay's aspect list for a category ----------------------------------------------

class TCGiant_Sync_API {
	public static $aspects = array();
	public static $fail    = false;
	public static $calls   = 0;
	public static function instance() { return new self(); }
	public function get_category_aspects( $category_id ) {
		self::$calls++;
		if ( self::$fail ) { return new WP_Error( 'ebay', 'Token expired.' ); }
		return self::$aspects;
	}
}

function aspect( $name, $required = false, $mode = 'FREE_TEXT', $values = array() ) {
	return array( 'name' => $name, 'required' => $required, 'multi' => false, 'mode' => $mode, 'selection' => '', 'values' => $values );
}

// ---- the plugin's logic, verbatim -----------------------------------------------------

class Specifics {
	const MAX_SPECIFICS       = 30;
	const MAX_SPECIFIC_NAME   = 40;
	const MAX_SPECIFIC_VALUE  = 65;
	const MAX_ASPECT_CHOICES  = 60;

	private static function normalize_aspect_name( $name ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
	}

	private function collect_attribute_specifics( WC_Product $product ) {
		$specifics = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) { continue; }
			if ( $attribute->get_variation() ) { continue; }
			$name = wc_attribute_label( $attribute->get_name(), $product );
			if ( '' === trim( (string) $name ) ) { continue; }
			$values = array_filter( array_map( 'trim', (array) $attribute->get_options() ) );
			if ( empty( $values ) ) { continue; }
			$value = implode( ', ', $values );
			$specifics[ mb_substr( $name, 0, self::MAX_SPECIFIC_NAME ) ] = mb_substr( $value, 0, self::MAX_SPECIFIC_VALUE );
		}
		return $specifics;
	}

	/** The coin/grading fields. Stubbed to whatever the test hands in. */
	public $configured = array();
	private function collect_configured_specifics( array $settings ) { return $this->configured; }

	private function collect_entered_specifics( array $settings ) {
		$entered = $settings['specifics'] ?? array();
		if ( ! is_array( $entered ) ) { return array(); }

		$out = array();
		foreach ( $entered as $name => $value ) {
			if ( is_array( $value ) ) { continue; }
			$name  = trim( (string) $name );
			$value = trim( (string) $value );
			if ( '' === $name || '' === $value ) { continue; }
			$out[ mb_substr( $name, 0, self::MAX_SPECIFIC_NAME ) ] = mb_substr( $value, 0, self::MAX_SPECIFIC_VALUE );
		}
		return $out;
	}

	private function canonicalize_specific_names( array $specifics, array $settings ) {
		$category_id = $settings['category_id'] ?? '';
		if ( empty( $category_id ) || empty( $specifics ) ) { return $specifics; }
		$aspects = TCGiant_Sync_API::instance()->get_category_aspects( $category_id );
		if ( is_wp_error( $aspects ) || empty( $aspects ) ) { return $specifics; }

		$canonical = array();
		foreach ( $aspects as $a ) {
			if ( empty( $a['name'] ) ) { continue; }
			$canonical[ self::normalize_aspect_name( $a['name'] ) ] = $a['name'];
		}
		$out = array();
		foreach ( $specifics as $name => $value ) {
			$key = self::normalize_aspect_name( $name );
			$out[ isset( $canonical[ $key ] ) ? $canonical[ $key ] : $name ] = $value;
		}
		return $out;
	}

	/** Stands in for add_derived_specifics(): applied to the map, owned by no bucket. */
	public $derived = array();

	private function build_specifics_map( WC_Product $product, array $settings ) {
		$specifics = array_merge(
			$this->collect_attribute_specifics( $product ),
			$this->collect_configured_specifics( $settings ),
			$this->collect_entered_specifics( $settings )
		);
		foreach ( $this->derived as $name => $value ) {
			if ( ! isset( $specifics[ $name ] ) ) { $specifics[ $name ] = $value; }
		}
		return $this->canonicalize_specific_names( $specifics, $settings );
	}

	public function describe_aspects( WC_Product $product, array $settings ) {
		$category_id = (string) ( $settings['category_id'] ?? '' );

		if ( '' === trim( $category_id ) ) {
			return array( 'status' => 'no_category', 'message' => 'Choose an eBay category first', 'category_id' => '', 'aspects' => array(), 'extra' => array() );
		}

		$aspects = TCGiant_Sync_API::instance()->get_category_aspects( $category_id );
		if ( is_wp_error( $aspects ) ) {
			return array( 'status' => 'lookup_failed', 'message' => $aspects->get_error_message(), 'category_id' => $category_id, 'aspects' => array(), 'extra' => array() );
		}

		$buckets = array(
			'attribute' => $this->collect_attribute_specifics( $product ),
			'listing'   => $this->collect_configured_specifics( $settings ),
			'entered'   => $this->collect_entered_specifics( $settings ),
		);

		$sources = array();
		foreach ( $buckets as $source => $bucket ) {
			foreach ( $bucket as $name => $value ) {
				if ( '' === trim( (string) $value ) ) { continue; }
				$sources[ self::normalize_aspect_name( $name ) ] = array( $source, $name );
			}
		}

		$provided = array();
		$display  = array();
		foreach ( $this->build_specifics_map( $product, $settings ) as $name => $value ) {
			$key              = self::normalize_aspect_name( $name );
			$provided[ $key ] = (string) $value;
			$display[ $key ]  = (string) $name;
		}

		$required = array();
		$optional = array();
		$listed   = array();

		foreach ( $aspects as $a ) {
			if ( empty( $a['name'] ) ) { continue; }
			$key            = self::normalize_aspect_name( $a['name'] );
			$listed[ $key ] = true;
			$value          = isset( $provided[ $key ] ) ? $provided[ $key ] : '';
			$origin         = ( '' !== trim( $value ) && isset( $sources[ $key ] ) ) ? $sources[ $key ] : array( '', '' );
			$offered        = array_values( (array) ( $a['values'] ?? array() ) );

			$row = array(
				'name'     => (string) $a['name'],
				'required' => ! empty( $a['required'] ),
				'mode'     => (string) ( $a['mode'] ?? 'FREE_TEXT' ),
				'multi'    => ! empty( $a['multi'] ),
				'choices'  => array_slice( $offered, 0, self::MAX_ASPECT_CHOICES ),
				'truncated' => count( $offered ) > self::MAX_ASPECT_CHOICES,
				'value'    => $value,
				'source'   => $origin[0],
				'from'     => $origin[1],
			);

			if ( $row['required'] ) { $required[] = $row; } else { $optional[] = $row; }
		}

		$extra   = array();
		$entered = array();
		foreach ( $provided as $key => $value ) {
			if ( '' === trim( $value ) || isset( $listed[ $key ] ) ) { continue; }
			$name           = isset( $display[ $key ] ) ? $display[ $key ] : $key;
			$extra[ $name ] = $value;
			if ( isset( $sources[ $key ] ) && 'entered' === $sources[ $key ][0] ) { $entered[ $name ] = $value; }
		}

		return array( 'status' => 'ok', 'message' => '', 'category_id' => $category_id, 'aspects' => array_merge( $required, $optional ), 'extra' => $extra, 'entered_extra' => $entered );
	}

	/** The pre-flight the push already ran, now fed by the same map. */
	public function find_missing_required_aspects( WC_Product $product, array $settings ) {
		$aspects = TCGiant_Sync_API::instance()->get_category_aspects( $settings['category_id'] ?? '' );
		if ( is_wp_error( $aspects ) || empty( $aspects ) ) { return array(); }

		$provided = array();
		foreach ( $this->build_specifics_map( $product, $settings ) as $name => $value ) {
			$provided[ self::normalize_aspect_name( $name ) ] = $value;
		}
		$missing = array();
		foreach ( $aspects as $a ) {
			if ( empty( $a['required'] ) || empty( $a['name'] ) ) { continue; }
			$key = self::normalize_aspect_name( $a['name'] );
			if ( ! isset( $provided[ $key ] ) || '' === trim( (string) $provided[ $key ] ) ) { $missing[] = $a['name']; }
		}
		return $missing;
	}
}

/** The admin's sanitiser and its save decision, verbatim. */
class Saver {
	public static function sanitize_specifics_input( $raw ) {
		if ( ! is_array( $raw ) ) { return array(); }
		$clean = array();
		foreach ( wp_unslash( $raw ) as $name => $value ) {
			if ( is_array( $value ) ) { continue; }
			$name  = trim( sanitize_text_field( (string) $name ) );
			$value = trim( sanitize_text_field( (string) $value ) );
			if ( '' === $name ) { continue; }
			$clean[ $name ] = $value;
		}
		return $clean;
	}

	/** Returns the stored meta after a save. null means "deleted". */
	public static function save( $stored, $post, $present_key, $values_key ) {
		if ( empty( $post[ $present_key ] ) ) {
			return $stored;                       // panel not on screen: leave it alone
		}
		$posted = self::sanitize_specifics_input( $post[ $values_key ] ?? array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( $posted as $name => $value ) {
			if ( '' === $value ) { unset( $stored[ $name ] ); continue; }
			$stored[ $name ] = $value;
		}

		return empty( $stored ) ? null : $stored;
	}
}

// =====================================================================================

$x = new Specifics();

// The networking seller's category, as eBay describes it.
TCGiant_Sync_API::$aspects = array(
	aspect( 'Brand', true ),
	aspect( 'Type', true, 'SELECTION_ONLY', array( 'Network Switch', 'Router', 'Firewall' ) ),
	aspect( 'MPN', true ),
	aspect( 'Number of LAN Ports', false ),
	aspect( 'Colour', false ),
);
$cat = array( 'category_id' => '51292' );

echo "\nTHE CASE THAT PROMPTED THIS: A SWITCH WITH NO ITEM SPECIFICS\n" . str_repeat( '=', 122 ) . "\n";

$bare = new WC_Product();
$d    = $x->describe_aspects( $bare, $cat );
check( 'the lookup succeeds', $d['status'], 'ok' );
check( 'five aspects are described', count( $d['aspects'] ), 5 );
check( 'required ones come first', array_map( function( $a ) { return $a['name']; }, $d['aspects'] ), array( 'Brand', 'Type', 'MPN', 'Number of LAN Ports', 'Colour' ) );
$missing = array_values( array_map( function( $a ) { return $a['name']; }, array_filter( $d['aspects'], function( $a ) { return $a['required'] && '' === $a['value']; } ) ) );
check( '  and all three required ones are empty', $missing, array( 'Brand', 'Type', 'MPN' ) );
check( 'the push would have failed on the same three', $x->find_missing_required_aspects( $bare, $cat ), array( 'Brand', 'Type', 'MPN' ) );
check( "Type's allowed values are offered", $d['aspects'][1]['choices'], array( 'Network Switch', 'Router', 'Firewall' ) );
check( '  and its mode says a choice is required', $d['aspects'][1]['mode'], 'SELECTION_ONLY' );

echo "\nFILLING THEM IN - BY ATTRIBUTE, AND IN THE PANEL\n" . str_repeat( '=', 122 ) . "\n";

$with_attrs = new WC_Product( array(
	new WC_Product_Attribute( 'Brand', array( 'NETGEAR' ) ),
	new WC_Product_Attribute( 'Colour', array( 'Grey' ) ),
) );
$d = $x->describe_aspects( $with_attrs, $cat );
check( 'Brand is satisfied by the product attribute', $d['aspects'][0]['value'], 'NETGEAR' );
check( '  and the panel can say where it came from', $d['aspects'][0]['source'], 'attribute' );
check( '  naming the attribute itself', $d['aspects'][0]['from'], 'Brand' );
check( 'Type is still missing', $d['aspects'][1]['value'], '' );
check( '  with no source to report', $d['aspects'][1]['source'], '' );

$typed = array_merge( $cat, array( 'specifics' => array( 'Type' => 'Network Switch', 'MPN' => 'GS524UP' ) ) );
$d     = $x->describe_aspects( $with_attrs, $typed );
check( 'what was typed in the panel is used', $d['aspects'][1]['value'], 'Network Switch' );
check( '  and is reported as entered, not as an attribute', $d['aspects'][1]['source'], 'entered' );
check( 'nothing required is left empty now', $x->find_missing_required_aspects( $with_attrs, $typed ), array() );

echo "\nWHO WINS WHEN TWO SOURCES DISAGREE\n" . str_repeat( '=', 122 ) . "\n";

$clash = new WC_Product( array( new WC_Product_Attribute( 'Brand', array( 'Netgear (old)' ) ) ) );
$d     = $x->describe_aspects( $clash, array_merge( $cat, array( 'specifics' => array( 'Brand' => 'NETGEAR' ) ) ) );
check( 'the panel entry beats the attribute', $d['aspects'][0]['value'], 'NETGEAR' );
check( '  and says so', $d['aspects'][0]['source'], 'entered' );

// Spelled differently, same aspect. This is the one a naive merge gets wrong:
// both survive as separate keys until the names are canonicalised.
$d = $x->describe_aspects(
	new WC_Product( array( new WC_Product_Attribute( 'number of lan ports', array( '24' ) ) ) ),
	array_merge( $cat, array( 'specifics' => array( 'Number Of LAN-Ports' => '48' ) ) )
);
check( 'differently spelled names are one aspect, and the panel wins', $d['aspects'][3]['value'], '48' );

$x->configured = array( 'Brand' => 'From the grading fields' );
$d = $x->describe_aspects( $bare, array_merge( $cat, array( 'specifics' => array( 'Brand' => 'Typed' ) ) ) );
check( 'the panel beats a configured listing field too', $d['aspects'][0]['value'], 'Typed' );
$d = $x->describe_aspects( $bare, $cat );
check( 'a configured field still counts when nothing was typed', $d['aspects'][0]['source'], 'listing' );
$x->configured = array();

echo "\nSPECIFICS EBAY DOES NOT LIST FOR THE CATEGORY\n" . str_repeat( '=', 122 ) . "\n";

$d = $x->describe_aspects( new WC_Product( array( new WC_Product_Attribute( 'Warranty', array( '3 years' ) ) ) ), $cat );
check( 'they are reported separately, not silently dropped', $d['extra'], array( 'Warranty' => '3 years' ) );
check( '  and do not appear among the aspects', count( $d['aspects'] ), 5 );

echo "\nWHEN EBAY CANNOT BE ASKED\n" . str_repeat( '=', 122 ) . "\n";

TCGiant_Sync_API::$fail = true;
$d = $x->describe_aspects( $bare, $cat );
check( 'the panel says the lookup failed', $d['status'], 'lookup_failed' );
check( '  and does not claim everything is fine', $d['aspects'], array() );
check( "  passing on eBay's reason", $d['message'], 'Token expired.' );
check( 'the push still lets the listing through, as it always has', $x->find_missing_required_aspects( $bare, $cat ), array() );
TCGiant_Sync_API::$fail = false;

$d = $x->describe_aspects( $bare, array( 'category_id' => '' ) );
check( 'no category is its own answer', $d['status'], 'no_category' );

TCGiant_Sync_API::$calls = 0;
$x->describe_aspects( $bare, $cat );
check( 'one description costs at most a handful of lookups (all cached)', TCGiant_Sync_API::$calls <= 3, true );

echo "\nSAVING WHAT WAS TYPED\n" . str_repeat( '=', 122 ) . "\n";

check( 'an array value is dropped, not stored as an empty string',
	Saver::sanitize_specifics_input( array( 'Type' => array( 'a', 'b' ), 'Brand' => 'NETGEAR' ) ),
	array( 'Brand' => 'NETGEAR' ) );
check( 'a blank value is KEPT, because an emptied box means remove it',
	Saver::sanitize_specifics_input( array( 'Type' => '   ', 'Brand' => 'NETGEAR' ) ), array( 'Type' => '', 'Brand' => 'NETGEAR' ) );
check( '  though a blank one is never sent to eBay', ( new Specifics() )->describe_aspects( new WC_Product(), array_merge( $cat, array( 'specifics' => array( 'Brand' => '' ) ) ) )['aspects'][0]['value'], '' );
check( 'blank names are dropped', Saver::sanitize_specifics_input( array( '  ' => 'x', 'Brand' => 'NETGEAR' ) ), array( 'Brand' => 'NETGEAR' ) );
check( 'markup in a value is stripped', Saver::sanitize_specifics_input( array( 'Type' => '<b>Switch</b>' ) ), array( 'Type' => 'Switch' ) );
check( 'markup in a NAME is stripped too', Saver::sanitize_specifics_input( array( '<i>Type</i>' => 'Switch' ) ), array( 'Type' => 'Switch' ) );
check( 'slashes added by WordPress are removed', Saver::sanitize_specifics_input( array( 'Type' => "Bob\\'s switch" ) ), array( 'Type' => "Bob's switch" ) );
check( 'a non-array is nothing at all', Saver::sanitize_specifics_input( 'Type=Switch' ), array() );

$stored = array( 'Type' => 'Network Switch' );
check( 'a save with the panel never opened leaves the stored values alone',
	Saver::save( $stored, array(), '_ebay_export_specifics_present', '_ebay_export_specifics' ), $stored );
check( 'a save with the panel open and the box emptied removes that one',
	Saver::save( $stored, array( '_ebay_export_specifics_present' => '1', '_ebay_export_specifics' => array( 'Type' => '' ) ), '_ebay_export_specifics_present', '_ebay_export_specifics' ), null );
check( 'a save with the panel open writes what is in it',
	Saver::save( $stored, array( '_ebay_export_specifics_present' => '1', '_ebay_export_specifics' => array( 'Type' => 'Router' ) ), '_ebay_export_specifics_present', '_ebay_export_specifics' ), array( 'Type' => 'Router' ) );
check( 'the push button uses the same rule with its own field names, and merges too',
	Saver::save( $stored, array( 'specifics_present' => '1', 'specifics' => array( 'MPN' => 'GS524UP' ) ), 'specifics_present', 'specifics' ),
	array( 'Type' => 'Network Switch', 'MPN' => 'GS524UP' ) );

echo "\nA PRODUCT MOVED TO ANOTHER CATEGORY KEEPS WHAT WAS TYPED FOR THE OLD ONE\n" . str_repeat( '=', 122 ) . "\n";

// The panel only ever renders the aspects eBay lists for the CURRENT category,
// so a product moved between categories posts a map that never mentions the
// values typed for the old one. Replacing wholesale deleted them - one line
// under a panel that had just said they were still being sent.
$old = array( 'Brand' => 'NETGEAR', 'Type' => 'Network Switch' );
check( 'a save for a category that lists neither keeps both',
	Saver::save( $old, array( 'specifics_present' => '1', 'specifics' => array( 'Model' => 'GS524UP' ) ), 'specifics_present', 'specifics' ),
	array( 'Brand' => 'NETGEAR', 'Type' => 'Network Switch', 'Model' => 'GS524UP' ) );
check( '  and an emptied box still removes exactly that one',
	Saver::save( $old, array( 'specifics_present' => '1', 'specifics' => array( 'Brand' => '' ) ), 'specifics_present', 'specifics' ),
	array( 'Type' => 'Network Switch' ) );
check( '  emptying every stored one deletes the meta',
	Saver::save( array( 'Brand' => 'NETGEAR' ), array( 'specifics_present' => '1', 'specifics' => array( 'Brand' => '' ) ), 'specifics_present', 'specifics' ), null );

// And they are offered back as editable boxes, because a stored value that never
// gets a box again can never be corrected or removed.
$x2 = new Specifics();
$d  = $x2->describe_aspects( $bare, array_merge( $cat, array( 'specifics' => array( 'Warranty' => '3 years' ) ) ) );
check( 'an entered specific the category does not list is offered back',
	$d['entered_extra'], array( 'Warranty' => '3 years' ) );
$d = $x2->describe_aspects( new WC_Product( array( new WC_Product_Attribute( 'Warranty', array( '3 years' ) ) ) ), $cat );
check( '  but one that comes from an attribute is not editable here',
	$d['entered_extra'], array() );

// Anything owned by no bucket at all - a derived value - kept its comparison key
// and was shown to the merchant as "circulateduncirculated".
$x2->derived = array( 'Circulated/Uncirculated' => 'Uncirculated' );
$d = $x2->describe_aspects( $bare, $cat );
check( 'a derived specific is named properly, not normalised',
	array_keys( $d['extra'] ), array( 'Circulated/Uncirculated' ) );
$x2->derived = array();

echo "\nLIMITS\n" . str_repeat( '=', 122 ) . "\n";

$long = array_merge( $cat, array( 'specifics' => array( str_repeat( 'N', 60 ) => str_repeat( 'v', 90 ) ) ) );
$d    = $x->describe_aspects( $bare, $long );
check( 'a long name is cut to eBay\'s limit', strlen( array_keys( $d['extra'] )[0] ), 40 );
check( 'a long value is cut to eBay\'s limit', strlen( array_values( $d['extra'] )[0] ), 65 );

TCGiant_Sync_API::$aspects = array( aspect( 'Type', true, 'SELECTION_ONLY', array_map( function( $i ) { return 'Choice ' . $i; }, range( 1, 200 ) ) ) );
$d = $x->describe_aspects( $bare, $cat );
check( 'a category with 200 suggested values offers 60', count( $d['aspects'][0]['choices'] ), 60 );
check( '  and says the list was cut, so the panel offers free text instead', $d['aspects'][0]['truncated'], true );
TCGiant_Sync_API::$aspects = array( aspect( 'Type', true, 'SELECTION_ONLY', array( 'Network Switch', 'Router' ) ) );
$d = $x->describe_aspects( $bare, $cat );
check( 'a short list is not cut, so a dropdown is safe', $d['aspects'][0]['truncated'], false );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
