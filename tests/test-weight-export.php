<?php
/**
 * Harness: package weight and size on export.
 *
 * The property that matters is not any single conversion but the ROUND TRIP.
 * The importer writes weights back from eBay every scheduled sync, so what goes
 * out comes back in and goes out again. This runs that loop six times, across
 * every store unit, and asserts it reaches a fixed point immediately. It also
 * runs the loop with the rounding the design nearly used, and asserts that one
 * DOES drift - so the test is known to have teeth.
 */

$OPTIONS  = array();
$PRODUCTS = array();

function get_option( $n, $d = false ) { global $OPTIONS; return array_key_exists( $n, $OPTIONS ) ? $OPTIONS[ $n ] : $d; }
function wc_format_decimal( $n, $dp = false ) { return false === $dp ? (string) $n : number_format( (float) $n, $dp, '.', '' ); }
function wc_get_product( $id ) { global $PRODUCTS; return $PRODUCTS[ $id ] ?? null; }

class WC_Product {
	public $w = '', $l = '', $wd = '', $h = '', $status = 'publish', $children = array();
	public function __construct( $w = '', $l = '', $wd = '', $h = '' ) { $this->w = $w; $this->l = $l; $this->wd = $wd; $this->h = $h; }
	public function get_weight() { return $this->w; }
	public function get_length() { return $this->l; }
	public function get_width()  { return $this->wd; }
	public function get_height() { return $this->h; }
	public function get_status() { return $this->status; }
	public function get_children() { return $this->children; }
	public function is_type( $t ) { return 'variable' === $t && $this instanceof WC_Product_Variable; }
}
class WC_Product_Variable extends WC_Product {}

class TCGiant_Sync_API {
	public static $site = 'US';
	public static function instance() { return new self(); }
	public function get_marketplace_config() { return array( 'site' => self::$site ); }
}

// ---- exporter side, verbatim ------------------------------------------------------
class Exporter {
	public function build_shipping_package_xml( WC_Product $product, array $settings ) {
		if ( empty( $settings['send_package_details'] ) ) { return ''; }
		$source = $this->package_source_product( $product );
		$weight = (float) $source->get_weight();
		if ( $weight <= 0 ) { return ''; }
		$system    = $this->export_measurement_system();
		$is_metric = ( 'Metric' === $system );
		$w_unit    = get_option( 'woocommerce_weight_unit', 'kg' );
		$d_unit    = get_option( 'woocommerce_dimension_unit', 'cm' );
		list( $major, $minor ) = self::weight_to_ebay( $weight, $w_unit, $system );
		$xml  = '<ShippingPackageDetails>' . "\n";
		$xml .= "\t<MeasurementUnit>" . $system . "</MeasurementUnit>\n";
		$xml .= "\t<WeightMajor unit=\"" . ( $is_metric ? 'kg' : 'lbs' ) . "\">" . $major . "</WeightMajor>\n";
		$xml .= "\t<WeightMinor unit=\"" . ( $is_metric ? 'g' : 'oz' ) . "\">" . $minor . "</WeightMinor>\n";
		$dims = array( 'PackageLength' => $source->get_length(), 'PackageWidth' => $source->get_width(), 'PackageDepth' => $source->get_height() );
		foreach ( $dims as $tag => $raw ) {
			$value = self::dimension_to_ebay( (float) $raw, $d_unit, $system );
			if ( '' !== $value ) {
				$xml .= "\t<" . $tag . " unit=\"" . ( $is_metric ? 'cm' : 'in' ) . "\">" . $value . "</" . $tag . ">\n";
			}
		}
		$xml .= '</ShippingPackageDetails>' . "\n";
		return $xml;
	}
	private function package_source_product( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) { return $product; }
		$heaviest = $product; $max = (float) $product->get_weight();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child || 'publish' !== $child->get_status() ) { continue; }
			$w = (float) $child->get_weight();
			if ( $w > $max ) { $max = $w; $heaviest = $child; }
		}
		return $heaviest;
	}
	private function export_measurement_system() {
		$site = TCGiant_Sync_API::instance()->get_marketplace_config()['site'];
		return in_array( $site, array( 'US', 'eBayMotors' ), true ) ? 'English' : 'Metric';
	}
	public static function weight_to_ebay( $weight, $unit, $system ) {
		$grams_per = array( 'kg' => 1000.0, 'g' => 1.0, 'lbs' => 453.59237, 'oz' => 28.349523125 );
		$grams     = (float) $weight * ( $grams_per[ $unit ] ?? 1000.0 );
		if ( 'Metric' === $system ) { $n = (int) round( $grams ); $per_major = 1000; }
		else { $n = (int) round( $grams / 28.349523125 ); $per_major = 16; }
		if ( $grams > 0 && $n < 1 ) { $n = 1; }
		return array( intdiv( $n, $per_major ), $n % $per_major );
	}
	public static function dimension_to_ebay( $value, $unit, $system ) {
		if ( $value <= 0 ) { return ''; }
		$cm_per = array( 'mm' => 0.1, 'cm' => 1.0, 'm' => 100.0, 'in' => 2.54, 'yd' => 91.44 );
		$cm     = (float) $value * ( $cm_per[ $unit ] ?? 1.0 );
		$out    = round( ( 'Metric' === $system ) ? $cm : $cm / 2.54, 2 );
		if ( $out <= 0 ) { $out = 0.01; }
		return rtrim( rtrim( number_format( $out, 2, '.', '' ), '0' ), '.' );
	}
	/** The rounding the design nearly shipped. Kept only to prove the test bites. */
	public static function weight_to_ebay_ceil( $weight, $unit, $system ) {
		$grams_per = array( 'kg' => 1000.0, 'g' => 1.0, 'lbs' => 453.59237, 'oz' => 28.349523125 );
		$grams     = (float) $weight * ( $grams_per[ $unit ] ?? 1000.0 );
		if ( 'Metric' === $system ) { $n = (int) ceil( round( $grams, 6 ) ); $per_major = 1000; }
		else { $n = (int) ceil( round( $grams / 28.349523125, 6 ) ); $per_major = 16; }
		return array( intdiv( $n, $per_major ), $n % $per_major );
	}
}

// ---- importer side, verbatim from 3.13.2 ------------------------------------------
function import_weight( $major, $minor, $system ) {
	$store_unit = get_option( 'woocommerce_weight_unit', 'lbs' );
	if ( 'Metric' === $system ) { $value = $major + $minor / 1000; $from = 'kg'; }
	else { $value = $major + $minor / 16; $from = 'lbs'; }
	if ( $value <= 0 ) { return ''; }
	if ( 'lbs' === $from && 'kg' === $store_unit )       { $value = $value * 0.45359237; }
	elseif ( 'lbs' === $from && 'oz' === $store_unit )   { $value = $value * 16; }
	elseif ( 'lbs' === $from && 'g' === $store_unit )    { $value = $value * 453.59237; }
	elseif ( 'kg' === $from && 'lbs' === $store_unit )   { $value = $value * 2.20462262; }
	elseif ( 'kg' === $from && 'oz' === $store_unit )    { $value = $value * 35.2739619; }
	elseif ( 'kg' === $from && 'g' === $store_unit )     { $value = $value * 1000; }
	return wc_format_decimal( $value, 2 );
}

// ---- scaffolding ---------------------------------------------------------------
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-60s got %-16s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}
function pair( $a ) { return $a[0] . ':' . $a[1]; }

/** Run import -> export -> import ... for $cycles, returning the export at each. */
function round_trip( $major, $minor, $system, $store_unit, $cycles, $fn ) {
	global $OPTIONS;
	$OPTIONS['woocommerce_weight_unit'] = $store_unit;
	$out = array();
	for ( $i = 0; $i < $cycles; $i++ ) {
		$stored = import_weight( $major, $minor, $system );
		list( $major, $minor ) = call_user_func( $fn, (float) $stored, $store_unit, $system );
		$out[] = pair( array( $major, $minor ) );
	}
	return $out;
}

echo "\nTHE ROUND TRIP REACHES A FIXED POINT AT ONCE, IN EVERY STORE UNIT\n" . str_repeat( '=', 112 ) . "\n";
$starts = array(
	'English' => array( array( 0, 1 ), array( 0, 2 ), array( 0, 3 ), array( 0, 5 ), array( 0, 15 ), array( 1, 0 ), array( 1, 8 ), array( 2, 1 ), array( 9, 0 ), array( 9, 8 ), array( 40, 3 ) ),
	'Metric'  => array( array( 0, 5 ), array( 0, 50 ), array( 0, 350 ), array( 0, 600 ), array( 0, 999 ), array( 1, 0 ), array( 2, 250 ), array( 20, 5 ) ),
);
$drift = array();
$identity_misses = array();
foreach ( $starts as $system => $list ) {
	foreach ( $list as $start ) {
		foreach ( array( 'kg', 'g', 'lbs', 'oz' ) as $unit ) {
			$trace = round_trip( $start[0], $start[1], $system, $unit, 6, array( 'Exporter', 'weight_to_ebay' ) );
			if ( count( array_unique( $trace ) ) !== 1 ) {
				$drift[] = sprintf( '%s %s in %s store: %s', pair( $start ), $system, $unit, implode( ' ', $trace ) );
			}
			if ( $trace[0] !== pair( $start ) ) {
				$identity_misses[] = sprintf( '%s %s in %s -> %s', pair( $start ), $system, $unit, $trace[0] );
			}
		}
	}
}
printf( "  (%d start weights x 4 store units x 6 cycles)\n", array_sum( array_map( 'count', $starts ) ) );
check( 'no combination ever moves after the first export', $drift, array() );
// The importer stores to two decimals of the store unit: ten-gram steps in a
// kilogram shop, ~4.5 g steps in a pounds shop. So a first export can land a
// few grams off the start. What must be true is that the loss is BOUNDED and
// happens ONCE - the fixed-point assertion above already proves it never
// compounds; this proves it is never more than the store's own resolution.
echo "  first-export differs from start only by the store unit's own resolution:\n";
$worst = 0.0;
foreach ( $identity_misses as $m ) {
	echo "    " . $m . "\n";
	if ( preg_match( '/^(\d+):(\d+) (\w+) in (\w+) -> (\d+):(\d+)$/', $m, $g ) ) {
		$per = ( 'Metric' === $g[3] ) ? array( 1000, 1 ) : array( 453.59237, 28.349523125 );
		$a   = $g[1] * $per[0] + $g[2] * $per[1];
		$b   = $g[5] * $per[0] + $g[6] * $per[1];
		$worst = max( $worst, abs( $a - $b ) );
	}
}
printf( "  (worst one-time loss: %.1f g)\n", $worst );
check( 'no first-export loss exceeds ten grams', $worst <= 10.0, true );
check( 'nothing in a gram or ounce store loses anything at all',
	count( array_filter( $identity_misses, function ( $m ) { return false !== strpos( $m, ' in g ' ) || false !== strpos( $m, ' in oz ' ); } ) ), 0 );

echo "\nAND THE ROUNDING THE DESIGN NEARLY USED DOES DRIFT - SO THIS TEST BITES\n" . str_repeat( '=', 112 ) . "\n";
$trace = round_trip( 0, 3, 'English', 'g', 6, array( 'Exporter', 'weight_to_ebay_ceil' ) );
echo "  3 oz card, gram-unit store, rounding up: " . implode( ' -> ', $trace ) . "\n";
check( 'rounding up ratchets with no fixed point', count( array_unique( $trace ) ) > 1 && end( $trace ) !== $trace[0], true );
$trace = round_trip( 0, 3, 'English', 'g', 6, array( 'Exporter', 'weight_to_ebay' ) );
check( '  nearest rounding holds at 3 oz', array_unique( $trace ), array( '0:3' ) );

echo "\nSPLITS AT THE BOUNDARIES\n" . str_repeat( '=', 112 ) . "\n";
check( '9 lbs -> 9 lb 0 oz',            pair( Exporter::weight_to_ebay( 9, 'lbs', 'English' ) ), '9:0' );
check( '9.5 lbs -> 9 lb 8 oz exactly',   pair( Exporter::weight_to_ebay( 9.5, 'lbs', 'English' ) ), '9:8' );
check( '16 oz -> 1 lb 0 oz',             pair( Exporter::weight_to_ebay( 16, 'oz', 'English' ) ), '1:0' );
check( '15.6 oz rounds to 1 lb 0 oz',     pair( Exporter::weight_to_ebay( 15.6, 'oz', 'English' ) ), '1:0' );
check( '15.4 oz rounds to 0 lb 15 oz',    pair( Exporter::weight_to_ebay( 15.4, 'oz', 'English' ) ), '0:15' );
check( '9 kg -> 19 lb 13 oz (English)',   pair( Exporter::weight_to_ebay( 9, 'kg', 'English' ) ), '19:13' );
check( '1000 g -> 1 kg 0 g',              pair( Exporter::weight_to_ebay( 1000, 'g', 'Metric' ) ), '1:0' );
check( '999.5 g -> 1 kg 0 g',             pair( Exporter::weight_to_ebay( 999.5, 'g', 'Metric' ) ), '1:0' );
check( '0.6 kg -> 0 kg 600 g',            pair( Exporter::weight_to_ebay( 0.6, 'kg', 'Metric' ) ), '0:600' );
check( '144 oz -> 4 kg 82 g (Metric)',    pair( Exporter::weight_to_ebay( 144, 'oz', 'Metric' ) ), '4:82' );
check( '9 lbs -> 4 kg 82 g (same mass)',  pair( Exporter::weight_to_ebay( 9, 'lbs', 'Metric' ) ), '4:82' );
check( 'a single card, 0.006 lbs, is 1 oz not nothing', pair( Exporter::weight_to_ebay( 0.006, 'lbs', 'English' ) ), '0:1' );
check( '0.0004 kg is 1 g not nothing',    pair( Exporter::weight_to_ebay( 0.0004, 'kg', 'Metric' ) ), '0:1' );
check( '0.0004 kg in English is 1 oz',    pair( Exporter::weight_to_ebay( 0.0004, 'kg', 'English' ) ), '0:1' );
check( 'unknown store unit is treated as kg', pair( Exporter::weight_to_ebay( 2, 'stone', 'Metric' ) ), '2:0' );

echo "\nDIMENSIONS\n" . str_repeat( '=', 112 ) . "\n";
check( '24 cm -> 9.45 in (English)',      Exporter::dimension_to_ebay( 24, 'cm', 'English' ), '9.45' );
check( '18 in -> 45.72 cm (Metric)',      Exporter::dimension_to_ebay( 18, 'in', 'Metric' ), '45.72' );
check( '250 mm -> 25 cm',                 Exporter::dimension_to_ebay( 250, 'mm', 'Metric' ), '25' );
check( '1 m -> 100 cm',                   Exporter::dimension_to_ebay( 1, 'm', 'Metric' ), '100' );
check( '1 yd -> 36 in',                   Exporter::dimension_to_ebay( 1, 'yd', 'English' ), '36' );
check( '10 cm stays 10 (Metric)',         Exporter::dimension_to_ebay( 10, 'cm', 'Metric' ), '10' );
check( 'zero is nothing',                 Exporter::dimension_to_ebay( 0, 'cm', 'Metric' ), '' );
check( 'trailing zeros trimmed',          Exporter::dimension_to_ebay( 2.5, 'cm', 'Metric' ), '2.5' );

echo "\nTHE PAYLOAD\n" . str_repeat( '=', 112 ) . "\n";
$x = new Exporter();
$OPTIONS = array( 'woocommerce_weight_unit' => 'lbs', 'woocommerce_dimension_unit' => 'in' );
TCGiant_Sync_API::$site = 'US';
$p = new WC_Product( '9', '18', '12', '3' );
check( 'setting off: nothing is sent', $x->build_shipping_package_xml( $p, array( 'send_package_details' => false ) ), '' );
check( 'no weight: nothing is sent, even with the setting on', $x->build_shipping_package_xml( new WC_Product( '', '18', '12', '3' ), array( 'send_package_details' => true ) ), '' );
$xml = $x->build_shipping_package_xml( $p, array( 'send_package_details' => true ) );
check( 'English payload names the system',   false !== strpos( $xml, '<MeasurementUnit>English</MeasurementUnit>' ), true );
check( '  9 lb 0 oz, both fields present',   false !== strpos( $xml, '<WeightMajor unit="lbs">9</WeightMajor>' ) && false !== strpos( $xml, '<WeightMinor unit="oz">0</WeightMinor>' ), true );
check( '  all three dimensions in inches',   substr_count( $xml, 'unit="in"' ), 3 );
TCGiant_Sync_API::$site = 'UK';
$OPTIONS = array( 'woocommerce_weight_unit' => 'kg', 'woocommerce_dimension_unit' => 'cm' );
$xml = $x->build_shipping_package_xml( new WC_Product( '0.6', '24', '10', '10' ), array( 'send_package_details' => true ) );
check( 'UK payload is Metric',                false !== strpos( $xml, '<MeasurementUnit>Metric</MeasurementUnit>' ), true );
check( '  0 kg 600 g',                        false !== strpos( $xml, '<WeightMajor unit="kg">0</WeightMajor>' ) && false !== strpos( $xml, '<WeightMinor unit="g">600</WeightMinor>' ), true );
check( '  24 x 10 x 10 cm',                   false !== strpos( $xml, '<PackageLength unit="cm">24</PackageLength>' ) && false !== strpos( $xml, '<PackageDepth unit="cm">10</PackageDepth>' ), true );
$xml = $x->build_shipping_package_xml( new WC_Product( '0.6', '', '', '' ), array( 'send_package_details' => true ) );
check( 'no dimensions: weight goes, dimensions do not', false !== strpos( $xml, 'WeightMinor' ) && false === strpos( $xml, 'Package' . 'Length' ), true );

echo "\nVARIABLE PRODUCTS USE THE HEAVIEST PUBLISHED VARIATION\n" . str_repeat( '=', 112 ) . "\n";
$parent = new WC_Product_Variable( '' );
$parent->children = array( 11, 12, 13, 14 );
$PRODUCTS[11] = new WC_Product( '0.5' );                 // 8-port
$PRODUCTS[12] = new WC_Product( '2.0' );                 // 48-port
$PRODUCTS[13] = new WC_Product( '1.0' );                 // 24-port
$PRODUCTS[14] = new WC_Product( '9.0' ); $PRODUCTS[14]->status = 'draft';
$xml = $x->build_shipping_package_xml( $parent, array( 'send_package_details' => true ) );
check( 'heaviest published child wins (2.0 kg), not the first (0.5)', false !== strpos( $xml, '<WeightMajor unit="kg">2</WeightMajor>' ), true );
check( '  a draft variation is ignored even though it is heavier', false === strpos( $xml, '>9<' ), true );
$parent2 = new WC_Product_Variable( '3.0' ); $parent2->children = array( 11, 13 );
$xml = $x->build_shipping_package_xml( $parent2, array( 'send_package_details' => true ) );
check( 'a parent heavier than every child is used',     false !== strpos( $xml, '<WeightMajor unit="kg">3</WeightMajor>' ), true );
$parent3 = new WC_Product_Variable( '' ); $parent3->children = array( 99 );
check( 'no weight anywhere: nothing is sent',           $x->build_shipping_package_xml( $parent3, array( 'send_package_details' => true ) ), '' );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
