<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Api\ApiException;
use ResoIdx\Api\Metadata;

final class MetadataTest extends TestCase {

	private const XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx" Version="4.0">
  <edmx:DataServices>
    <Schema xmlns="http://docs.oasis-open.org/odata/ns/edm" Namespace="org.reso.metadata">
      <EntityType Name="Property">
        <Key><PropertyRef Name="ListingKey"/></Key>
        <Property Name="ListingKey" Type="Edm.String"/>
        <Property Name="ListPrice" Type="Edm.Decimal"/>
        <Property Name="LivingAreaRange" Type="Edm.String"/>
      </EntityType>
      <EntityType Name="Media">
        <Property Name="MediaKey" Type="Edm.String"/>
        <Property Name="MediaURL" Type="Edm.String"/>
      </EntityType>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>
XML;

	public function test_reads_fields_of_one_entity_type(): void {
		$this->assertSame( array( 'ListingKey', 'ListPrice', 'LivingAreaRange' ), Metadata::fields( self::XML, 'Property' ) );
		$this->assertSame( array( 'MediaKey', 'MediaURL' ), Metadata::fields( self::XML, 'Media' ) );
		$this->assertSame( array(), Metadata::fields( self::XML, 'Member' ) );
	}

	public function test_rejects_invalid_xml(): void {
		$this->expectException( ApiException::class );
		Metadata::fields( '{"not":"xml"}', 'Property' );
	}
}
