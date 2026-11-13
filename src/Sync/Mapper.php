<?php
/**
 * RESO record → stored listing.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

/**
 * Turns a RESO Property record (with its Media) into the flat row the plugin
 * stores, and applies the IDX display rules that decide whether it may be shown.
 */
final class Mapper {

	private const MAX_PHOTOS = 50;

	/**
	 * Preferred ImageSizeDescription values, best first.
	 */
	private const SIZE_PREFERENCE = array( 'Large', 'Largest', 'Medium', 'Small', 'Thumbnail' );

	/**
	 * Set up the mapper.
	 *
	 * @param list<string> $statuses StandardStatus values that may be displayed.
	 */
	public function __construct( private array $statuses = array( 'Active' ) ) {}

	/**
	 * Whether a record may be displayed publicly.
	 *
	 * @param array<string, mixed> $record RESO Property record.
	 */
	public function is_displayable( array $record ): bool {
		if ( empty( $record['ListingKey'] ) ) {
			return false;
		}
		if ( false === self::bool( $record['InternetEntireListingDisplayYN'] ?? null ) ) {
			return false;
		}
		$status = (string) ( $record['StandardStatus'] ?? '' );
		return in_array( strtolower( $status ), array_map( 'strtolower', $this->statuses ), true );
	}

	/**
	 * Map a record, or return null when it must not be displayed.
	 *
	 * @param array<string, mixed> $r RESO Property record.
	 * @return array<string, mixed>|null
	 */
	public function map( array $r ): ?array {
		if ( ! $this->is_displayable( $r ) ) {
			return null;
		}

		$address_hidden = false === self::bool( $r['InternetAddressDisplayYN'] ?? null );
		$photos         = self::photos( is_array( $r['Media'] ?? null ) ? $r['Media'] : array() );

		return array(
			'listing_key'      => self::text( $r['ListingKey'], 64 ),
			'listing_id'       => self::text( $r['ListingId'] ?? $r['ListingKey'], 64 ),
			'status'           => self::text( $r['StandardStatus'] ?? '', 32 ),
			'property_type'    => self::text( $r['PropertyType'] ?? '', 64 ),
			'property_subtype' => self::text( $r['PropertySubType'] ?? '', 64 ),
			'price'            => self::int( $r['ListPrice'] ?? null ),
			'beds'             => self::int( $r['BedroomsTotal'] ?? null ),
			'baths'            => self::int( $r['BathroomsTotalInteger'] ?? null ),
			'living_area'      => self::text( $r['LivingArea'] ?? $r['LivingAreaRange'] ?? '', 32 ),
			'year_built'       => self::int( $r['YearBuilt'] ?? null ),
			'address'          => $address_hidden ? '' : self::street( $r ),
			'unit'             => $address_hidden ? '' : self::text( $r['UnitNumber'] ?? '', 32 ),
			'city'             => self::text( $r['City'] ?? '', 100 ),
			'region'           => self::text( $r['CityRegion'] ?? '', 100 ),
			'province'         => self::text( $r['StateOrProvince'] ?? '', 32 ),
			'postal_code'      => $address_hidden ? '' : self::text( $r['PostalCode'] ?? '', 16 ),
			'latitude'         => $address_hidden ? null : self::float( $r['Latitude'] ?? null ),
			'longitude'        => $address_hidden ? null : self::float( $r['Longitude'] ?? null ),
			'address_hidden'   => $address_hidden ? 1 : 0,
			'remarks'          => trim( (string) ( $r['PublicRemarks'] ?? '' ) ),
			'office_name'      => self::text( $r['ListOfficeName'] ?? '', 191 ),
			'agent_name'       => self::text( $r['ListAgentFullName'] ?? '', 191 ),
			'photos'           => $photos,
			'virtual_tour'     => self::url( $r['VirtualTourURLUnbranded'] ?? '' ),
			'listed_at'        => self::date( $r['ListingContractDate'] ?? null ),
			'modified_at'      => self::datetime( (string) ( $r['ModificationTimestamp'] ?? '' ) ),
			'details'          => self::details( $r ),
		);
	}

	/**
	 * Street line, from the address parts or UnparsedAddress.
	 *
	 * @param array<string, mixed> $r Record.
	 */
	private static function street( array $r ): string {
		$parts = array_filter(
			array(
				$r['StreetNumber'] ?? '',
				$r['StreetDirPrefix'] ?? '',
				$r['StreetName'] ?? '',
				$r['StreetSuffix'] ?? '',
				$r['StreetDirSuffix'] ?? '',
			),
			static fn ( $p ): bool => is_scalar( $p ) && '' !== trim( (string) $p )
		);
		if ( count( $parts ) >= 2 ) {
			return self::text( implode( ' ', array_map( 'trim', array_map( 'strval', $parts ) ) ), 191 );
		}
		$unparsed = trim( (string) ( $r['UnparsedAddress'] ?? '' ) );
		return self::text( explode( ',', $unparsed )[0], 191 );
	}

	/**
	 * Photo URLs: public, active photos at one size, preferred photo first.
	 *
	 * @param array<mixed> $media RESO Media records (untrusted feed data).
	 * @return list<string>
	 */
	public static function photos( array $media ): array {
		$media = array_values(
			array_filter(
				$media,
				static function ( $m ): bool {
					if ( ! is_array( $m ) || '' === self::url( $m['MediaURL'] ?? '' ) ) {
						return false;
					}
					if ( isset( $m['MediaStatus'] ) && 'active' !== strtolower( (string) $m['MediaStatus'] ) ) {
						return false;
					}
					if ( isset( $m['MediaCategory'] ) && ! str_contains( strtolower( (string) $m['MediaCategory'] ), 'photo' ) ) {
						return false;
					}
					if ( isset( $m['Permission'] ) ) {
						$perm = array_map( 'strtolower', array_map( 'strval', (array) $m['Permission'] ) );
						if ( array() !== $perm && ! in_array( 'public', $perm, true ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		$sizes = array_unique( array_filter( array_map( static fn ( array $m ): string => (string) ( $m['ImageSizeDescription'] ?? '' ), $media ) ) );
		if ( array() !== $sizes ) {
			$pick = null;
			foreach ( self::SIZE_PREFERENCE as $size ) {
				if ( in_array( $size, $sizes, true ) ) {
					$pick = $size;
					break;
				}
			}
			$pick ??= reset( $sizes );
			$media = array_values( array_filter( $media, static fn ( array $m ): bool => ( $m['ImageSizeDescription'] ?? '' ) === $pick ) );
		}

		usort(
			$media,
			static function ( array $a, array $b ): int {
				$pa = true === self::bool( $a['PreferredPhotoYN'] ?? null ) ? 0 : 1;
				$pb = true === self::bool( $b['PreferredPhotoYN'] ?? null ) ? 0 : 1;
				return array( $pa, (int) ( $a['Order'] ?? PHP_INT_MAX ) ) <=> array( $pb, (int) ( $b['Order'] ?? PHP_INT_MAX ) );
			}
		);

		$urls = array_values( array_unique( array_map( static fn ( array $m ): string => self::url( $m['MediaURL'] ), $media ) ) );
		return array_slice( $urls, 0, self::MAX_PHOTOS );
	}

	/**
	 * Secondary facts shown on the listing page.
	 *
	 * @param array<string, mixed> $r Record.
	 * @return array<string, string>
	 */
	private static function details( array $r ): array {
		$lot = '';
		if ( ! empty( $r['LotWidth'] ) && ! empty( $r['LotDepth'] ) ) {
			$lot = rtrim( rtrim( (string) $r['LotWidth'], '0' ), '.' ) . ' × ' . rtrim( rtrim( (string) $r['LotDepth'], '0' ), '.' );
		} elseif ( ! empty( $r['LotSizeArea'] ) ) {
			$lot = trim( $r['LotSizeArea'] . ' ' . ( $r['LotSizeUnits'] ?? '' ) );
		}

		$details = array(
			'style'       => self::join( $r['ArchitecturalStyle'] ?? '' ),
			'heating'     => self::join( $r['Heating'] ?? $r['HeatType'] ?? '' ),
			'cooling'     => self::join( $r['Cooling'] ?? '' ),
			'parking'     => null !== self::int( $r['ParkingTotal'] ?? null ) ? (string) self::int( $r['ParkingTotal'] ) : '',
			'lot'         => $lot,
			'taxes'       => null !== self::float( $r['TaxAnnualAmount'] ?? null ) ? (string) round( (float) $r['TaxAnnualAmount'] ) : '',
			'association' => null !== self::float( $r['AssociationFee'] ?? null ) ? (string) round( (float) $r['AssociationFee'] ) : '',
		);
		return array_filter( $details, static fn ( string $v ): bool => '' !== $v );
	}

	/**
	 * Normalise Y/N style values. Null when unknown.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function bool( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value || '' === $value ) {
			return null;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'y', 'yes', 'true' ), true );
	}

	/**
	 * Trimmed string, cut to a length.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Max characters.
	 */
	private static function text( $value, int $max ): string {
		return is_scalar( $value ) ? mb_substr( trim( (string) $value ), 0, $max ) : '';
	}

	/**
	 * Array or scalar as a comma-separated string.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function join( $value ): string {
		$items = array_filter( array_map( 'trim', array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) ) );
		return mb_substr( implode( ', ', $items ), 0, 191 );
	}

	/**
	 * Integer or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function int( $value ): ?int {
		return is_numeric( $value ) ? (int) round( (float) $value ) : null;
	}

	/**
	 * Float or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function float( $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * An http(s) URL or a demo photo reference, or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function url( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '#^' . preg_quote( DemoSource::PHOTO_SCHEME, '#' ) . '[A-Za-z0-9._-]+$#', $value ) ) {
			return $value;
		}
		return preg_match( '#^https?://#i', $value ) ? $value : '';
	}

	/**
	 * Y-m-d or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function date( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * MySQL datetime in UTC.
	 *
	 * @param string $value ISO timestamp.
	 */
	private static function datetime( string $value ): string {
		$ts = strtotime( $value );
		return gmdate( 'Y-m-d H:i:s', false === $ts ? 0 : $ts );
	}
}
