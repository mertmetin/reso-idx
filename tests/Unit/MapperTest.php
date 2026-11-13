<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Sync\Mapper;

final class MapperTest extends TestCase {

	private function record( array $overrides = array() ): array {
		return array_merge(
			array(
				'ListingKey'            => 'K1',
				'ListingId'             => 'X123',
				'StandardStatus'        => 'Active',
				'PropertyType'          => 'Residential',
				'PropertySubType'       => 'Townhouse',
				'ListPrice'             => 649900.0,
				'BedroomsTotal'         => 3,
				'BathroomsTotalInteger' => 2,
				'LivingAreaRange'       => '1100-1500',
				'StreetNumber'          => '12',
				'StreetName'            => 'Maple',
				'StreetSuffix'          => 'Ave',
				'UnitNumber'            => '4',
				'City'                  => 'Ottawa',
				'StateOrProvince'       => 'ON',
				'PostalCode'            => 'K1A 0A1',
				'Latitude'              => 45.42,
				'Longitude'             => -75.69,
				'PublicRemarks'         => "  Bright home.  ",
				'ListOfficeName'        => 'Example Realty',
				'HeatType'              => 'Forced Air',
				'TaxAnnualAmount'       => '4210.55',
				'LotWidth'              => '30.00',
				'LotDepth'              => '100.50',
				'ModificationTimestamp' => '2026-11-01T12:00:00Z',
			),
			$overrides
		);
	}

	public function test_maps_a_record(): void {
		$row = ( new Mapper() )->map( $this->record() );

		$this->assertSame( 'K1', $row['listing_key'] );
		$this->assertSame( 649900, $row['price'] );
		$this->assertSame( '12 Maple Ave', $row['address'] );
		$this->assertSame( '4', $row['unit'] );
		$this->assertSame( '1100-1500', $row['living_area'], 'Falls back to LivingAreaRange.' );
		$this->assertSame( 'Bright home.', $row['remarks'] );
		$this->assertSame( '2026-11-01 12:00:00', $row['modified_at'] );
		$this->assertSame( 'Forced Air', $row['details']['heating'], 'Falls back to HeatType.' );
		$this->assertSame( '4211', $row['details']['taxes'] );
		$this->assertSame( '30 × 100.5', $row['details']['lot'] );
		$this->assertArrayNotHasKey( 'cooling', $row['details'], 'Empty details are left out.' );
	}

	public function test_uses_unparsed_address_when_parts_are_missing(): void {
		$row = ( new Mapper() )->map( $this->record( array( 'StreetNumber' => null, 'StreetName' => null, 'StreetSuffix' => null, 'UnparsedAddress' => '99 Elm St, Ottawa, ON K1A 0A1' ) ) );
		$this->assertSame( '99 Elm St', $row['address'] );
	}

	public function test_skips_statuses_that_are_not_displayable(): void {
		$mapper = new Mapper( array( 'Active' ) );
		$this->assertNull( $mapper->map( $this->record( array( 'StandardStatus' => 'Closed' ) ) ) );
		$this->assertNotNull( ( new Mapper( array( 'Active', 'Active Under Contract' ) ) )->map( $this->record( array( 'StandardStatus' => 'active under contract' ) ) ) );
	}

	public function test_respects_internet_display_opt_out(): void {
		$this->assertNull( ( new Mapper() )->map( $this->record( array( 'InternetEntireListingDisplayYN' => false ) ) ) );
		$this->assertNull( ( new Mapper() )->map( $this->record( array( 'InternetEntireListingDisplayYN' => 'N' ) ) ) );
		$this->assertNotNull( ( new Mapper() )->map( $this->record( array( 'InternetEntireListingDisplayYN' => null ) ) ), 'Unknown means allowed.' );
	}

	public function test_hides_address_and_location_when_seller_asks(): void {
		$row = ( new Mapper() )->map( $this->record( array( 'InternetAddressDisplayYN' => false ) ) );

		$this->assertSame( 1, $row['address_hidden'] );
		$this->assertSame( '', $row['address'] );
		$this->assertSame( '', $row['unit'] );
		$this->assertSame( '', $row['postal_code'] );
		$this->assertNull( $row['latitude'] );
		$this->assertSame( 'Ottawa', $row['city'], 'City can still be shown.' );
	}

	public function test_photos_keep_one_size_and_put_the_preferred_photo_first(): void {
		$media = array(
			array( 'MediaURL' => 'https://img.test/2-thumb.jpg', 'Order' => 2, 'ImageSizeDescription' => 'Thumbnail' ),
			array( 'MediaURL' => 'https://img.test/2-large.jpg', 'Order' => 2, 'ImageSizeDescription' => 'Large' ),
			array( 'MediaURL' => 'https://img.test/1-large.jpg', 'Order' => 1, 'ImageSizeDescription' => 'Large' ),
			array( 'MediaURL' => 'https://img.test/3-large.jpg', 'Order' => 3, 'ImageSizeDescription' => 'Large', 'PreferredPhotoYN' => true ),
		);
		$this->assertSame(
			array( 'https://img.test/3-large.jpg', 'https://img.test/1-large.jpg', 'https://img.test/2-large.jpg' ),
			Mapper::photos( $media )
		);
	}

	public function test_photos_drop_private_inactive_and_non_photo_media(): void {
		$media = array(
			array( 'MediaURL' => 'https://img.test/ok.jpg', 'Permission' => array( 'Public' ) ),
			array( 'MediaURL' => 'https://img.test/private.jpg', 'Permission' => array( 'Private' ) ),
			array( 'MediaURL' => 'https://img.test/deleted.jpg', 'MediaStatus' => 'Deleted' ),
			array( 'MediaURL' => 'https://img.test/plan.pdf', 'MediaCategory' => 'Document' ),
			array( 'MediaURL' => 'javascript:alert(1)' ),
			array( 'MediaURL' => '//evil.test/x.jpg' ),
		);
		$this->assertSame( array( 'https://img.test/ok.jpg' ), Mapper::photos( $media ) );
	}

	public function test_rejects_non_http_virtual_tours(): void {
		$row = ( new Mapper() )->map( $this->record( array( 'VirtualTourURLUnbranded' => 'javascript:alert(1)' ) ) );
		$this->assertSame( '', $row['virtual_tour'] );
	}
}
