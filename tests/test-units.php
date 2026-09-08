<?php
/**
 * Harness: weight and dimension import for a shop outside the United States.
 *
 * A shop in the UK reported a 0.6 kg oil filter arriving as 17.01 kg and a
 * 350 g part as 9.92 kg. Both figures are reproduced here from the OLD logic
 * first, so the fix is measured against the exact fault rather than assumed.
 */

// ---- the world ---------------------------------------------------------------
$OPTIONS = array();
$LOG     = array();

function get_option( $name, $default = false ) {
	global $OPTIONS;
	return array_key_exists( $name, $OPTIONS ) ? $OPTIONS[ $name ] : $default;
}
function update_option( $name, $value ) {
	global $OPTIONS;
	$OPTIONS[ $name ] = $value;
}
function wc_format_decimal( $n, $dp = false ) {
	return false === $dp ? (string) $n : number_format( (float) $n, $dp, '.', '' );
}
class TCGiant_Sync_Logger {
	public static function log( $m ) { global $LOG; $LOG[] = $m; }
}
class TCGiant_Sync_OAuth {
	public static function instance() { return new self(); }
	public function get_settings() { return get_option( 'tcgiant_sync_ebay_settings', array() ); }
}
class TCGiant_Sync_API {
	const MARKETPLACES = array(
		'EBAY_US' => array( 'label' => 'United States',  'site_id' => '0',   'country' => 'US', 'currency' => 'USD', 'site' => 'US' ),
		'EBAY_GB' => array( 'label' => 'United Kingdom', 'site_id' => '3',   'country' => 'GB', 'currency' => 'GBP', 'site' => 'UK' ),
		'EBAY_CA' => array( 'label' => 'Canada',         'site_id' => '2',   'country' => 'CA', 'currency' => 'CAD', 'site' => 'Canada' ),
		'EBAY_AU' => array( 'label' => 'Australia',      'site_id' => '15',  'country' => 'AU', 'currency' => 'AUD', 'site' => 'Australia' ),
		'EBAY_DE' => array( 'label' => 'Germany',        'site_id' => '77',  'country' => 'DE', 'currency' => 'EUR', 'site' => 'Germany' ),
		'EBAY_FR' => array( 'label' => 'France',         'site_id' => '71',  'country' => 'FR', 'currency' => 'EUR', 'site' => 'France' ),
		'EBAY_IT' => array( 'label' => 'Italy',          'site_id' => '101', 'country' => 'IT', 'currency' => 'EUR', 'site' => 'Italy' ),
		'EBAY_ES' => array( 'label' => 'Spain',          'site_id' => '186', 'country' => 'ES', 'currency' => 'EUR', 'site' => 'Spain' ),
	);
	public static function scalar_value( $raw ) {
		if ( is_string( $raw ) || is_numeric( $raw ) ) { return (string) $raw; }
		if ( is_array( $raw ) ) {
			foreach ( array( '#text', '__text', 'value', 0, '0' ) as $key ) {
				if ( isset( $raw[ $key ] ) && ( is_string( $raw[ $key ] ) || is_numeric( $raw[ $key ] ) ) ) { return (string) $raw[ $key ]; }
			}
		}
		return '';
	}
}

// ---- the mapper, verbatim ------------------------------------------------------
class Mapper {
	private function parse_measurement_value( $raw ) {
		if ( null === $raw ) { return ''; }
		return TCGiant_Sync_API::scalar_value( $raw );
	}

	private function parse_measurement_unit( $raw, $type = 'weight', $item_system = '' ) {
		if ( is_array( $raw ) && isset( $raw['@attributes']['unit'] ) ) {
			return (string) $raw['@attributes']['unit'];
		}

		$imperial_unit = ( 'weight' === $type ) ? 'lbs' : 'in';
		$metric_unit   = ( 'weight' === $type ) ? 'kg' : 'cm';

		if ( is_array( $raw ) && isset( $raw['@attributes']['measurementSystem'] ) ) {
			return 'English' === $raw['@attributes']['measurementSystem'] ? $imperial_unit : $metric_unit;
		}

		if ( 'metric' === $item_system ) { return $metric_unit; }
		if ( 'imperial' === $item_system ) { return $imperial_unit; }

		return $this->get_marketplace_default_unit( $type );
	}

	private function item_measurement_system( $ebay_item ) {
		$site = isset( $ebay_item['Site'] ) ? TCGiant_Sync_API::scalar_value( $ebay_item['Site'] ) : '';
		if ( '' === $site ) { return ''; }
		$this->maybe_adopt_marketplace( $site );
		if ( 'US' === $site || 'eBayMotors' === $site ) { return 'imperial'; }
		foreach ( TCGiant_Sync_API::MARKETPLACES as $m ) {
			if ( $m['site'] === $site ) { return 'metric'; }
		}
		return '';
	}

	private function maybe_adopt_marketplace( $site ) {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		$settings = get_option( 'tcgiant_sync_ebay_settings', array() );
		if ( ! empty( $settings['marketplace'] ) ) { return; }
		foreach ( TCGiant_Sync_API::MARKETPLACES as $id => $m ) {
			if ( $m['site'] !== $site ) { continue; }
			$settings['marketplace'] = $id;
			update_option( 'tcgiant_sync_ebay_settings', $settings );
			TCGiant_Sync_Logger::log( sprintf(
				'Marketplace was not set. Taking %s from your eBay listings; change it in Settings if that is wrong.',
				$m['label']
			) );
			return;
		}
	}

	private function get_marketplace_default_unit( $type = 'weight' ) {
		$settings = TCGiant_Sync_OAuth::instance()->get_settings();
		$marketplace = ! empty( $settings['marketplace'] ) ? $settings['marketplace'] : 'EBAY_US';
		$is_imperial = ( 'EBAY_US' === $marketplace );
		if ( 'weight' === $type ) { return $is_imperial ? 'lbs' : 'kg'; }
		return $is_imperial ? 'in' : 'cm';
	}

	private function convert_weight_to_store_unit( $value, $from_unit ) {
		if ( $value <= 0 ) { return ''; }
		$store_unit = get_option( 'woocommerce_weight_unit', 'lbs' );
		if ( 'lbs' === $from_unit && 'kg' === $store_unit )       { $value = $value * 0.45359237; }
		elseif ( 'lbs' === $from_unit && 'oz' === $store_unit )   { $value = $value * 16; }
		elseif ( 'lbs' === $from_unit && 'g' === $store_unit )    { $value = $value * 453.59237; }
		elseif ( 'kg' === $from_unit && 'lbs' === $store_unit )   { $value = $value * 2.20462262; }
		elseif ( 'kg' === $from_unit && 'oz' === $store_unit )    { $value = $value * 35.2739619; }
		elseif ( 'kg' === $from_unit && 'g' === $store_unit )     { $value = $value * 1000; }
		return wc_format_decimal( $value, 2 );
	}

	private function convert_dimension_to_store_unit( $value, $from_unit ) {
		if ( $value <= 0 ) { return ''; }
		$store_unit = get_option( 'woocommerce_dimension_unit', 'in' );
		$from_unit  = strtolower( $from_unit );
		if ( 'inches' === $from_unit ) $from_unit = 'in';
		if ( empty( $from_unit ) ) $from_unit = $this->get_marketplace_default_unit( 'dimension' );
		if ( 'in' === $from_unit && 'cm' === $store_unit )      { $value = $value * 2.54; }
		elseif ( 'in' === $from_unit && 'mm' === $store_unit )  { $value = $value * 25.4; }
		elseif ( 'in' === $from_unit && 'm' === $store_unit )   { $value = $value * 0.0254; }
		elseif ( 'cm' === $from_unit && 'in' === $store_unit )  { $value = $value / 2.54; }
		elseif ( 'cm' === $from_unit && 'mm' === $store_unit )  { $value = $value * 10; }
		elseif ( 'cm' === $from_unit && 'm' === $store_unit )   { $value = $value / 100; }
		return wc_format_decimal( $value, 2 );
	}

	/** The package block from map_ebay_to_woo(), verbatim. */
	public function map_package( $ebay_item ) {
		$product_data = array( 'weight' => '', 'length' => '', 'width' => '', 'height' => '' );
		if ( isset( $ebay_item['ShippingPackageDetails'] ) ) {
			$pkg    = $ebay_item['ShippingPackageDetails'];
			$system = $this->item_measurement_system( $ebay_item );

			$weight_major = $this->parse_measurement_value( $pkg['WeightMajor'] ?? null );
			$weight_minor = $this->parse_measurement_value( $pkg['WeightMinor'] ?? null );

			if ( '' !== $weight_major || '' !== $weight_minor ) {
				$major = (float) $weight_major;
				$minor = (float) $weight_minor;
				$major_unit = $this->parse_measurement_unit( $pkg['WeightMajor'] ?? null, 'weight', $system );
				$is_metric  = in_array( strtolower( $major_unit ), array( 'kg', 'g' ), true );
				if ( $is_metric ) {
					$total_kg = $major + ( $minor / 1000 );
					$product_data['weight'] = $this->convert_weight_to_store_unit( $total_kg, 'kg' );
				} else {
					$total_lbs = $major + ( $minor / 16 );
					$product_data['weight'] = $this->convert_weight_to_store_unit( $total_lbs, 'lbs' );
				}
			}

			$length_val = $this->parse_measurement_value( $pkg['PackageLength'] ?? null );
			$width_val  = $this->parse_measurement_value( $pkg['PackageWidth'] ?? null );
			$depth_val  = $this->parse_measurement_value( $pkg['PackageDepth'] ?? null );
			$dim_unit = $this->parse_measurement_unit( $pkg['PackageLength'] ?? $pkg['PackageWidth'] ?? null, 'dimension', $system );
			if ( '' !== $length_val ) { $product_data['length'] = $this->convert_dimension_to_store_unit( (float) $length_val, $dim_unit ); }
			if ( '' !== $width_val )  { $product_data['width']  = $this->convert_dimension_to_store_unit( (float) $width_val, $dim_unit ); }
			if ( '' !== $depth_val )  { $product_data['height'] = $this->convert_dimension_to_store_unit( (float) $depth_val, $dim_unit ); }
		}
		return $product_data;
	}

	/** What the OLD code did, for the before/after. */
	public function map_package_before( $ebay_item ) {
		$pkg   = $ebay_item['ShippingPackageDetails'];
		$major = (float) $this->parse_measurement_value( $pkg['WeightMajor'] ?? null );
		$minor = (float) $this->parse_measurement_value( $pkg['WeightMinor'] ?? null );
		$unit  = $this->get_marketplace_default_unit( 'weight' ); // attributes stripped -> marketplace
		if ( 'kg' === $unit ) {
			$w = $this->convert_weight_to_store_unit( $major + $minor / 1000, 'kg' );
		} else {
			$w = $this->convert_weight_to_store_unit( $major + $minor / 16, 'lbs' );
		}
		$d = $this->convert_dimension_to_store_unit( (float) $this->parse_measurement_value( $pkg['PackageWidth'] ), $this->get_marketplace_default_unit( 'dimension' ) );
		return array( 'weight' => $w, 'width' => $d );
	}
}

// ---- scaffolding --------------------------------------------------------------
$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-58s got %-14s want %s\n", $ok ? 'PASS' : 'FAIL', $label, var_export( $got, true ), var_export( $want, true ) );
}
function world( $marketplace, $weight_unit = 'kg', $dim_unit = 'cm' ) {
	global $OPTIONS, $LOG;
	$OPTIONS = array(
		'tcgiant_sync_ebay_settings' => $marketplace === null ? array() : array( 'marketplace' => $marketplace ),
		'woocommerce_weight_unit'    => $weight_unit,
		'woocommerce_dimension_unit' => $dim_unit,
	);
	$LOG = array();
}
function uk_item( $major, $minor, $len = '24', $wid = '10', $dep = '10', $site = 'UK' ) {
	// Attributes stripped, exactly as they arrive.
	return array( 'Site' => $site, 'ShippingPackageDetails' => array(
		'WeightMajor' => $major, 'WeightMinor' => $minor,
		'PackageLength' => $len, 'PackageWidth' => $wid, 'PackageDepth' => $dep,
	) );
}

$m = new Mapper();

echo "\nTHE CUSTOMER'S FIGURES, REPRODUCED FROM THE OLD LOGIC\n" . str_repeat( '=', 104 ) . "\n";
world( null ); // never set the marketplace, kg/cm shop
$before = $m->map_package_before( uk_item( '0', '600' ) );
check( 'oil filter, 0.6 kg on eBay, arrived as', $before['weight'], '17.01' );
check( '  and 10 cm wide arrived as',            $before['width'],  '25.40' );
$before = $m->map_package_before( uk_item( '0', '350' ) );
check( 'the 350 g part arrived as',             $before['weight'], '9.92' );

echo "\nAND NOW\n" . str_repeat( '=', 104 ) . "\n";
world( null );
$r = $m->map_package( uk_item( '0', '600' ) );
check( 'oil filter: 0.6 kg stays 0.6 kg',       $r['weight'], '0.60' );
check( '  length 24 cm stays 24',               $r['length'], '24.00' );
check( '  width 10 cm stays 10',                $r['width'],  '10.00' );
check( '  depth 10 cm stays 10',                $r['height'], '10.00' );
$r = $m->map_package( uk_item( '0', '350' ) );
check( '350 g part: stays 0.35 kg',             $r['weight'], '0.35' );

echo "\nTHE MARKETPLACE FILLS ITSELF IN, ONCE, ONLY WHEN BLANK\n" . str_repeat( '=', 104 ) . "\n";
// The first call above ran with the marketplace blank and Site=UK.
check( 'blank marketplace was set from the listing', $OPTIONS['tcgiant_sync_ebay_settings']['marketplace'] ?? null, 'EBAY_GB' );
check( '  and it said so in the log', count( $LOG ) === 1 && false !== strpos( $LOG[0], 'United Kingdom' ), true );
world( 'EBAY_US' );
$m->map_package( uk_item( '0', '600' ) );
check( 'a marketplace someone chose is left alone (static guard)', $OPTIONS['tcgiant_sync_ebay_settings']['marketplace'], 'EBAY_US' );
// A fresh mapper still respects a deliberately-set value.
$m2 = new Mapper();
world( 'EBAY_US' );
$m2->map_package( uk_item( '0', '600', '24', '10', '10', 'Germany' ) );
check( 'a chosen marketplace is never overwritten', $OPTIONS['tcgiant_sync_ebay_settings']['marketplace'], 'EBAY_US' );
check( '  and nothing was logged about it', $LOG, array() );

echo "\nTHE LISTING'S SITE DECIDES, WHATEVER THE SETTING SAYS\n" . str_repeat( '=', 104 ) . "\n";
world( 'EBAY_US' );
check( 'UK listing on a US-set shop is still metric',  $m->map_package( uk_item( '0', '600' ) )['weight'], '0.60' );
world( 'EBAY_GB' );
check( 'US listing on a UK-set shop is imperial (1 lb 8 oz -> kg)', $m->map_package( uk_item( '1', '8', '24', '10', '10', 'US' ) )['weight'], '0.68' );
world( 'EBAY_GB', 'lbs', 'in' );
check( '  same, lbs shop', $m->map_package( uk_item( '1', '8', '24', '10', '10', 'US' ) )['weight'], '1.50' );
check( '  10 in on an inch shop stays 10', $m->map_package( uk_item( '1', '8', '24', '10', '10', 'US' ) )['width'], '10.00' );
world( 'EBAY_US' );
check( 'Germany is metric',    $m->map_package( uk_item( '2', '250', '24', '10', '10', 'Germany' ) )['weight'], '2.25' );
check( 'Australia is metric',  $m->map_package( uk_item( '0', '500', '24', '10', '10', 'Australia' ) )['weight'], '0.50' );
world( 'EBAY_GB' );
check( 'eBayMotors is imperial', $m->map_package( uk_item( '2', '0', '24', '10', '10', 'eBayMotors' ) )['weight'], '0.91' );

echo "\nAN ATTRIBUTE, WHEN ONE SURVIVES, STILL WINS\n" . str_repeat( '=', 104 ) . "\n";
world( 'EBAY_US' );
$it = uk_item( array( '@attributes' => array( 'unit' => 'kg' ), '0' => '0' ), '600', '24', '10', '10', 'US' );
check( 'unit="kg" beats a US site', $m->map_package( $it )['weight'], '0.60' );
$it = uk_item( '0', '600' );
$it['ShippingPackageDetails']['PackageLength'] = array( '@attributes' => array( 'measurementSystem' => 'Metric' ), '0' => '24' );
check( 'measurementSystem=Metric on a dimension now means cm, not kg', $m->map_package( $it )['length'], '24.00' );
world( 'EBAY_GB', 'kg', 'in' );
check( '  and converts to an inch shop correctly', $m->map_package( $it )['length'], '9.45' );

echo "\nWHEN THE LISTING SAYS NOTHING, BEHAVIOUR IS UNCHANGED\n" . str_repeat( '=', 104 ) . "\n";
world( null );
$anon = uk_item( '1', '8', '24', '10', '10', '' );
unset( $anon['Site'] );
check( 'no site, no setting: the old US default (imperial)', $m->map_package( $anon )['weight'], '0.68' );
world( 'EBAY_GB' );
check( 'no site, UK setting: metric as before',            $m->map_package( $anon )['weight'], '1.01' );
world( 'EBAY_US' );
$unknown = uk_item( '1', '8', '24', '10', '10', 'Narnia' );
check( 'unknown site falls to the setting',                $m->map_package( $unknown )['weight'], '0.68' );

echo "\nEDGES\n" . str_repeat( '=', 104 ) . "\n";
world( null );
check( 'zero weight is blank', $m->map_package( uk_item( '0', '0' ) )['weight'], '' );
check( 'no package block at all', $m->map_package( array( 'Site' => 'UK' ) ), array( 'weight' => '', 'length' => '', 'width' => '', 'height' => '' ) );
check( 'major only (2 kg)', $m->map_package( uk_item( '2', '' ) )['weight'], '2.00' );
check( 'minor only, 5 g, is 0.01 at two places', $m->map_package( uk_item( '', '5' ) )['weight'], '0.01' );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
