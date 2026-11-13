<?php
/**
 * Live RESO Web API feed.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

use ResoIdx\Api\Client;
use ResoIdx\Api\ODataQuery;

/**
 * Pulls Property and Media records from a RESO Web API server.
 */
final class RemoteSource implements Source {

	/**
	 * Property fields the plugin can use. Only the ones the server has are
	 * requested (see Metadata).
	 */
	public const PROPERTY_FIELDS = array(
		'ListingKey',
		'ListingId',
		'StandardStatus',
		'PropertyType',
		'PropertySubType',
		'ListPrice',
		'BedroomsTotal',
		'BathroomsTotalInteger',
		'LivingArea',
		'LivingAreaRange',
		'LotSizeArea',
		'LotSizeUnits',
		'LotWidth',
		'LotDepth',
		'YearBuilt',
		'UnparsedAddress',
		'StreetNumber',
		'StreetDirPrefix',
		'StreetName',
		'StreetSuffix',
		'StreetDirSuffix',
		'UnitNumber',
		'City',
		'CityRegion',
		'StateOrProvince',
		'PostalCode',
		'Latitude',
		'Longitude',
		'PublicRemarks',
		'ListOfficeName',
		'ListAgentFullName',
		'VirtualTourURLUnbranded',
		'ListingContractDate',
		'ModificationTimestamp',
		'InternetEntireListingDisplayYN',
		'InternetAddressDisplayYN',
		'ParkingTotal',
		'Heating',
		'HeatType',
		'Cooling',
		'ArchitecturalStyle',
		'TaxAnnualAmount',
		'AssociationFee',
	);

	public const MEDIA_FIELDS = array(
		'MediaKey',
		'ResourceRecordKey',
		'MediaURL',
		'Order',
		'MediaCategory',
		'MediaStatus',
		'Permission',
		'PreferredPhotoYN',
		'ImageSizeDescription',
		'ShortDescription',
	);

	private const MEDIA_KEYS_PER_REQUEST = 10;

	/**
	 * Set up the feed.
	 *
	 * @param Client                      $client       API client.
	 * @param string                      $resource_name     Property resource name.
	 * @param string                      $scope_filter Raw OData filter limiting the feed, e.g. City eq 'Ottawa'.
	 * @param list<string>                $statuses     StandardStatus values to display.
	 * @param string                      $media_mode   `resource` (separate Media requests), `expand` or `none`.
	 * @param string                      $media_filter Raw OData filter added to Media requests.
	 * @param array<string, list<string>> $available    Field names per resource from $metadata. Empty = unknown.
	 */
	public function __construct(
		private Client $client,
		private string $resource_name = 'Property',
		private string $scope_filter = '',
		private array $statuses = array( 'Active' ),
		private string $media_mode = 'resource',
		private string $media_filter = '',
		private array $available = array()
	) {}

	/**
	 * Records changed after the cursor.
	 *
	 * The server is asked for `ModificationTimestamp ge T`, and records at T
	 * that the cursor already covers are dropped here. If a whole page shares
	 * timestamp T, that group is read in full so the sync never stalls.
	 *
	 * @param Cursor $after Last record processed.
	 * @param int    $limit Batch size.
	 * @return list<array<string, mixed>>
	 */
	public function changes( Cursor $after, int $limit ): array {
		$ts   = ODataQuery::datetime( $after->timestamp );
		$base = $this->property_query();

		$query = $base
			->where( 'ModificationTimestamp ge ' . $ts )
			->order_by( 'ModificationTimestamp,ListingKey' )
			->top( $limit );
		if ( 'expand' === $this->media_mode ) {
			$query = $query->expand( 'Media' );
		}
		$rows = $this->client->page( $this->resource_name, $query )['value'];

		$all_tied = count( $rows ) >= $limit && array() === array_filter(
			$rows,
			static fn ( array $r ): bool => 0 !== Cursor::compare_times( (string) ( $r['ModificationTimestamp'] ?? '' ), $after->timestamp )
		);
		if ( $all_tied ) {
			$tie = $base->where( 'ModificationTimestamp eq ' . $ts )->order_by( 'ListingKey' )->top( $limit );
			if ( 'expand' === $this->media_mode ) {
				$tie = $tie->expand( 'Media' );
			}
			$rows = $this->client->all( $this->resource_name, $tie );
		}

		$rows = array_values(
			array_filter(
				$rows,
				static fn ( array $r ): bool => isset( $r['ListingKey'], $r['ModificationTimestamp'] )
					&& $after->is_before( (string) $r['ModificationTimestamp'], (string) $r['ListingKey'] )
			)
		);
		usort( $rows, array( self::class, 'compare' ) );

		if ( 'resource' === $this->media_mode && array() !== $rows ) {
			$rows = $this->attach_media( $rows );
		}
		return $rows;
	}

	/**
	 * Keys of every displayable listing in scope.
	 *
	 * @return list<string>
	 */
	public function active_keys(): array {
		$query = $this->property_query()
			->where_in( 'StandardStatus', $this->statuses )
			->select( array( 'ListingKey' ) )
			->order_by( 'ListingKey' )
			->top( 500 );
		$rows  = $this->client->all( $this->resource_name, $query, 1000 );
		return array_values( array_unique( array_map( static fn ( array $r ): string => (string) ( $r['ListingKey'] ?? '' ), $rows ) ) );
	}

	/**
	 * Base Property query: scope filter and field selection.
	 */
	private function property_query(): ODataQuery {
		$query = ODataQuery::create()->where( $this->scope_filter );
		$known = $this->available[ $this->resource_name ] ?? array();
		if ( array() !== $known ) {
			$query = $query->select( array_values( array_intersect( self::PROPERTY_FIELDS, $known ) ) );
		}
		return $query;
	}

	/**
	 * Fetch Media for a batch of listings and attach it under `Media`.
	 *
	 * @param list<array<string, mixed>> $rows Property records.
	 * @return list<array<string, mixed>>
	 */
	private function attach_media( array $rows ): array {
		$by_key = array();
		$keys   = array_map( static fn ( array $r ): string => (string) $r['ListingKey'], $rows );
		$known  = $this->available['Media'] ?? array();

		foreach ( array_chunk( $keys, self::MEDIA_KEYS_PER_REQUEST ) as $chunk ) {
			$query = ODataQuery::create()
				->where_in( 'ResourceRecordKey', $chunk )
				->where( $this->media_filter )
				->top( 500 );
			if ( array() !== $known ) {
				$query = $query->select( array_values( array_intersect( self::MEDIA_FIELDS, $known ) ) );
			}
			foreach ( $this->client->all( 'Media', $query ) as $media ) {
				$by_key[ (string) ( $media['ResourceRecordKey'] ?? '' ) ][] = $media;
			}
		}

		foreach ( $rows as &$row ) {
			$row['Media'] = $by_key[ (string) $row['ListingKey'] ] ?? array();
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Order by (ModificationTimestamp, ListingKey).
	 *
	 * @param array<string, mixed> $a Record.
	 * @param array<string, mixed> $b Record.
	 */
	private static function compare( array $a, array $b ): int {
		$order = Cursor::compare_times( (string) $a['ModificationTimestamp'], (string) $b['ModificationTimestamp'] );
		return 0 !== $order ? $order : strcmp( (string) $a['ListingKey'], (string) $b['ListingKey'] );
	}
}
