<?php
/**
 * Listings on eBay Motors imported for real, for test-motors.php (3.28.9): a
 * vintage motorcycle part in 35560 and a whole motorcycle, both reported as
 * site eBayMotors, through the real TCGiant_Sync_Mapper - the world and the
 * import() of support/imported-revise-import.php, whose own output is set
 * aside. What each product holds is printed as JSON.
 *
 * usage: php tests/support/motors-import.php
 *
 * @package TCGiant_Sync
 */

ob_start();
require __DIR__ . '/imported-revise-import.php';
ob_end_clean();

$motors_common = '<Currency>USD</Currency><Site>eBayMotors</Site><Country>US</Country><ListingDetails><StartTime>2026-03-01T10:00:00.000Z</StartTime><EndTime>2026-12-01T10:00:00.000Z</EndTime></ListingDetails>';

$MOTORS = '<ItemID>256100000801</ItemID><Title>Triumph T120 rocker cover, original</Title><SKU>TRI-RC-120</SKU>' . $motors_common
	. '<Quantity>3</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">85.0</CurrentPrice></SellingStatus>'
	. '<PrimaryCategory><CategoryID>35560</CategoryID><CategoryName>eBay Motors:Parts &amp; Accessories:Motorcycle &amp; Scooter Parts:Vintage Motorcycle Parts</CategoryName></PrimaryCategory>'
	. '<ConditionID>3000</ConditionID><ConditionDisplayName>Used</ConditionDisplayName>'
	. policies( '240000081', '240000082', '240000083' )
	. '<Location>Leeds, AL</Location><PostalCode>35094</PostalCode><DispatchTimeMax>2</DispatchTimeMax>'
	. '<PictureDetails><PictureURL>https://i.ebayimg.com/images/g/MMM/s-l1600.jpg</PictureURL></PictureDetails>';

$BIKE = '<ItemID>256100000802</ItemID><Title>1969 Triumph Bonneville T120</Title>' . $motors_common
	. '<Quantity>1</Quantity><ListingType>FixedPriceItem</ListingType><ListingDuration>GTC</ListingDuration>'
	. '<SellingStatus><ListingStatus>Active</ListingStatus><QuantitySold>0</QuantitySold><CurrentPrice currencyID="USD">14500.0</CurrentPrice></SellingStatus>'
	. '<PrimaryCategory><CategoryID>6472</CategoryID><CategoryName>eBay Motors:Motorcycles:Triumph</CategoryName></PrimaryCategory>'
	. '<ConditionID>3000</ConditionID>'
	. policies( '240000091', '240000092', '240000093' )
	. '<Location>Leeds, AL</Location><PostalCode>35094</PostalCode><DispatchTimeMax>5</DispatchTimeMax>';

$motors_out = array();
foreach ( array( 'motors' => $MOTORS, 'motorcycle' => $BIKE ) as $name => $xml ) {
	$item                              = ebay_item( $xml );
	$pid                               = import( $item );
	$motors_out['answers'][ $name ]    = $item;
	$motors_out['imported'][ $name ]   = array( 'id' => $pid, 'meta' => $META[ $pid ] );
}

echo json_encode( $motors_out );
