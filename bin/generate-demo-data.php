<?php
/**
 * Generates data/demo-listings.json: fictional listings in RESO format.
 *
 * Usage: php bin/generate-demo-data.php
 *
 * Seeded, so the output is the same on every run. Every street, office and
 * agent name here is invented; no real MLS data is used.
 *
 * @package ResoIdx
 */

mt_srand( 20261113 );

$neighbourhoods = array(
	array( 'Millbrook', 45.4215, -75.6972 ),
	array( 'Lakeview Heights', 45.3480, -75.8110 ),
	array( 'Northgate', 45.4680, -75.5240 ),
	array( 'Cedar Park', 45.2890, -75.7350 ),
	array( 'Riverside Commons', 45.3920, -75.6680 ),
);
$streets  = array( 'Maple', 'Birch', 'Willow', 'Cedar', 'Juniper', 'Aspen', 'Linden', 'Hawthorn', 'Larch', 'Sumac', 'Tamarack', 'Heron', 'Kestrel', 'Fieldstone', 'Orchard' );
$suffixes = array( 'Street', 'Avenue', 'Crescent', 'Drive', 'Way', 'Court', 'Lane' );
$offices  = array( 'Northstar Realty Group', 'Hearthside Properties', 'Bluewater Realty', 'Keystone Home Brokerage', 'Evergreen Realty Partners' );
$agents   = array( 'Alex Chen', 'Priya Natarajan', 'Sam Okafor', 'Jordan Leblanc', 'Taylor Brooks', 'Morgan Yilmaz', 'Casey Fournier', 'Riley Haddad' );
$styles   = array( '2-Storey', 'Bungalow', 'Split Level', '1 1/2 Storey' );

$types = array(
	array( 'Residential', 'Single Family Residence', array( 'house-a.svg', 'house-b.svg', 'house-c.svg' ), array( 549000, 1490000 ), array( 3, 5 ) ),
	array( 'Residential', 'Townhouse', array( 'townhouse.svg' ), array( 429000, 789000 ), array( 2, 4 ) ),
	array( 'Residential', 'Condominium', array( 'condo.svg' ), array( 289000, 699000 ), array( 1, 3 ) ),
);
$interiors = array( 'kitchen.svg', 'living.svg', 'bedroom.svg' );

$openers = array(
	'Sun-filled {home} on a quiet {street} in {hood}.',
	'Move-in ready {home} steps from parks and schools in {hood}.',
	'Thoughtfully updated {home} with an open layout, tucked into {hood}.',
	'Rarely offered {home} close to transit and shops in {hood}.',
);
$features = array(
	'The kitchen has quartz counters and a large island.',
	'Hardwood floors run through the main level.',
	'The primary bedroom has a walk-in closet and ensuite.',
	'A finished lower level adds a family room and office.',
	'The fenced backyard backs onto green space.',
	'Recent updates include windows, furnace and roof.',
	'Large windows bring in light all day.',
);

$base_time = strtotime( '2026-11-01T12:00:00Z' );
$records   = array();

for ( $i = 1; $i <= 60; $i++ ) {
	list( $hood, $lat, $lng ) = $neighbourhoods[ $i % count( $neighbourhoods ) ];
	list( $ptype, $sub, $exteriors, $price_range, $bed_range ) = $types[ mt_rand( 0, 9 ) < 6 ? 0 : ( mt_rand( 0, 1 ) ? 1 : 2 ) ];

	$beds   = mt_rand( $bed_range[0], $bed_range[1] );
	$baths  = max( 1, min( $beds, mt_rand( 1, 4 ) ) );
	$price  = (int) ( round( mt_rand( $price_range[0], $price_range[1] ) / 5000 ) * 5000 );
	$street = $streets[ mt_rand( 0, count( $streets ) - 1 ) ];
	$suffix = $suffixes[ mt_rand( 0, count( $suffixes ) - 1 ) ];
	$number = mt_rand( 12, 2400 );
	$area   = 'Condominium' === $sub ? mt_rand( 550, 1400 ) : mt_rand( 1100, 3400 );
	$noun   = array(
		'Single Family Residence' => 'detached home',
		'Townhouse'               => 'townhome',
		'Condominium'             => 'condo',
	)[ $sub ];

	$remarks = strtr(
		$openers[ mt_rand( 0, count( $openers ) - 1 ) ],
		array(
			'{home}'   => $beds . '-bedroom ' . $noun,
			'{street}' => strtolower( $suffix ),
			'{hood}'   => $hood,
		)
	);
	$picked  = array_rand( array_flip( $features ), 3 );
	$remarks .= ' ' . implode( ' ', (array) $picked );

	$media     = array();
	$photo_set = array_merge( array( $exteriors[ mt_rand( 0, count( $exteriors ) - 1 ) ] ), $interiors );
	foreach ( $photo_set as $order => $file ) {
		$media[] = array(
			'MediaKey'          => sprintf( 'DEMO-%03d-M%d', $i, $order ),
			'ResourceRecordKey' => sprintf( 'DEMO%05d', $i ),
			'MediaURL'          => 'assets/demo/' . $file,
			'MediaCategory'     => 'Photo',
			'MediaStatus'       => 'Active',
			'Order'             => $order,
			'PreferredPhotoYN'  => 0 === $order,
		);
	}

	$status = 'Active';
	if ( 0 === $i % 17 ) {
		$status = 'Closed';
	} elseif ( 0 === $i % 13 ) {
		$status = 'Pending';
	}

	$record = array(
		'ListingKey'                     => sprintf( 'DEMO%05d', $i ),
		'ListingId'                      => sprintf( 'D%07d', 1000000 + $i * 37 ),
		'StandardStatus'                 => $status,
		'PropertyType'                   => $ptype,
		'PropertySubType'                => $sub,
		'ListPrice'                      => $price,
		'BedroomsTotal'                  => $beds,
		'BathroomsTotalInteger'          => $baths,
		'LivingArea'                     => $area,
		'YearBuilt'                      => mt_rand( 1948, 2025 ),
		'StreetNumber'                   => (string) $number,
		'StreetName'                     => $street,
		'StreetSuffix'                   => $suffix,
		'UnitNumber'                     => 'Condominium' === $sub ? (string) mt_rand( 101, 2408 ) : null,
		'City'                           => 'Demo City',
		'CityRegion'                     => $hood,
		'StateOrProvince'                => 'ON',
		'PostalCode'                     => sprintf( 'X%dX %dX%d', mt_rand( 0, 9 ), mt_rand( 0, 9 ), mt_rand( 0, 9 ) ),
		'Latitude'                       => round( $lat + ( mt_rand( -1800, 1800 ) / 100000 ), 6 ),
		'Longitude'                      => round( $lng + ( mt_rand( -2500, 2500 ) / 100000 ), 6 ),
		'PublicRemarks'                  => $remarks,
		'ListOfficeName'                 => $offices[ mt_rand( 0, count( $offices ) - 1 ) ],
		'ListAgentFullName'              => $agents[ mt_rand( 0, count( $agents ) - 1 ) ],
		'ArchitecturalStyle'             => 'Condominium' === $sub ? array( 'Apartment' ) : array( $styles[ mt_rand( 0, count( $styles ) - 1 ) ] ),
		'Heating'                        => array( 'Forced Air', 'Natural Gas' ),
		'Cooling'                        => array( 'Central Air' ),
		'ParkingTotal'                   => 'Condominium' === $sub ? 1 : mt_rand( 1, 4 ),
		'TaxAnnualAmount'                => round( $price * 0.0105 ),
		'AssociationFee'                 => 'Condominium' === $sub ? mt_rand( 350, 820 ) : null,
		'InternetEntireListingDisplayYN' => 0 !== $i % 29,
		'InternetAddressDisplayYN'       => 0 !== $i % 23,
		'DaysOnMarket'                   => mt_rand( 1, 75 ),
		'ModificationTimestamp'          => gmdate( 'Y-m-d\TH:i:s\Z', $base_time + intdiv( $i, 3 ) * 3600 ),
		'Media'                          => $media,
	);

	$records[] = array_filter( $record, static fn ( $v ): bool => null !== $v );
}

$out = dirname( __DIR__ ) . '/data/demo-listings.json';
file_put_contents( $out, json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
printf( "Wrote %d listings to %s\n", count( $records ), $out );
