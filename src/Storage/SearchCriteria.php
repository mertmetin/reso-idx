<?php
/**
 * Listing search filters.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Storage;

/**
 * Validated search input. Built from untrusted request data with from_array().
 */
final class SearchCriteria {

	public const SORTS = array(
		'newest'     => 'listed_at DESC, modified_at DESC',
		'price_asc'  => 'price ASC',
		'price_desc' => 'price DESC',
		'beds_desc'  => 'beds DESC, price ASC',
	);

	/**
	 * Create criteria.
	 *
	 * @param string                                             $q         Free text: address, city, postal code or MLS number.
	 * @param string                                             $city      Exact city.
	 * @param string                                             $type      Property type, e.g. Residential.
	 * @param int|null                                           $min_price Minimum price.
	 * @param int|null                                           $max_price Maximum price.
	 * @param int|null                                           $beds      Minimum bedrooms.
	 * @param int|null                                           $baths     Minimum bathrooms.
	 * @param array{0: float, 1: float, 2: float, 3: float}|null $bounds South, west, north, east.
	 * @param string                                             $sort      Key of SORTS.
	 * @param int                                                $page      1-based page.
	 * @param int                                                $per_page  Page size.
	 */
	public function __construct(
		public readonly string $q = '',
		public readonly string $city = '',
		public readonly string $type = '',
		public readonly ?int $min_price = null,
		public readonly ?int $max_price = null,
		public readonly ?int $beds = null,
		public readonly ?int $baths = null,
		public readonly ?array $bounds = null,
		public readonly string $sort = 'newest',
		public readonly int $page = 1,
		public readonly int $per_page = 12
	) {}

	/**
	 * Build from request parameters, dropping anything invalid.
	 *
	 * @param array<string, mixed> $input    Raw input.
	 * @param int                  $per_page Default page size.
	 */
	public static function from_array( array $input, int $per_page = 12 ): self {
		$str = static fn ( string $k, int $max ): string => isset( $input[ $k ] ) && is_scalar( $input[ $k ] )
			? mb_substr( trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $input[ $k ] ) ?? '' ), 0, $max )
			: '';
		$num = static function ( string $k, int $min, int $max ) use ( $input ): ?int {
			if ( ! isset( $input[ $k ] ) || ! is_numeric( $input[ $k ] ) ) {
				return null;
			}
			$n = (int) $input[ $k ];
			return $n < $min ? null : min( $n, $max );
		};

		$bounds = null;
		if ( isset( $input['bounds'] ) && is_string( $input['bounds'] ) ) {
			$parts = array_map( 'trim', explode( ',', $input['bounds'] ) );
			if ( 4 === count( $parts ) && array() === array_filter( $parts, static fn ( $p ) => ! is_numeric( $p ) ) ) {
				list( $s, $w, $n, $e ) = array_map( 'floatval', $parts );
				if ( $s >= -90 && $n <= 90 && $s < $n && $w >= -180 && $e <= 180 && $w < $e ) {
					$bounds = array( $s, $w, $n, $e );
				}
			}
		}

		$sort = $str( 'sort', 20 );
		return new self(
			q: $str( 'q', 100 ),
			city: $str( 'city', 100 ),
			type: $str( 'type', 64 ),
			min_price: $num( 'min_price', 1, PHP_INT_MAX ),
			max_price: $num( 'max_price', 1, PHP_INT_MAX ),
			beds: $num( 'beds', 1, 20 ),
			baths: $num( 'baths', 1, 20 ),
			bounds: $bounds,
			sort: isset( self::SORTS[ $sort ] ) ? $sort : 'newest',
			page: $num( 'page', 1, 1000 ) ?? 1,
			per_page: $num( 'per_page', 1, 48 ) ?? max( 1, min( 48, $per_page ) )
		);
	}

	/**
	 * Parameters for links and the REST API (defaults left out).
	 *
	 * @return array<string, string|int>
	 */
	public function to_query_args(): array {
		$args = array(
			'q'         => $this->q,
			'city'      => $this->city,
			'type'      => $this->type,
			'min_price' => $this->min_price,
			'max_price' => $this->max_price,
			'beds'      => $this->beds,
			'baths'     => $this->baths,
			'sort'      => 'newest' === $this->sort ? '' : $this->sort,
		);
		return array_filter( $args, static fn ( $v ): bool => null !== $v && '' !== $v );
	}

	/**
	 * SQL WHERE clause with %-placeholders, and its arguments.
	 *
	 * @return array{0: string, 1: list<string|int|float>}
	 */
	public function where(): array {
		$sql  = array( '1=1' );
		$args = array();

		if ( '' !== $this->q ) {
			$like  = '%' . addcslashes( $this->q, '_%\\' ) . '%';
			$sql[] = '(address LIKE %s OR city LIKE %s OR region LIKE %s OR postal_code LIKE %s OR listing_id = %s)';
			array_push( $args, $like, $like, $like, $like, $this->q );
		}
		if ( '' !== $this->city ) {
			$sql[]  = 'city = %s';
			$args[] = $this->city;
		}
		if ( '' !== $this->type ) {
			$sql[]  = '(property_type = %s OR property_subtype = %s)';
			$args[] = $this->type;
			$args[] = $this->type;
		}
		if ( null !== $this->min_price ) {
			$sql[]  = 'price >= %d';
			$args[] = $this->min_price;
		}
		if ( null !== $this->max_price ) {
			$sql[]  = 'price <= %d';
			$args[] = $this->max_price;
		}
		if ( null !== $this->beds ) {
			$sql[]  = 'beds >= %d';
			$args[] = $this->beds;
		}
		if ( null !== $this->baths ) {
			$sql[]  = 'baths >= %d';
			$args[] = $this->baths;
		}
		if ( null !== $this->bounds ) {
			$sql[] = 'latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f';
			array_push( $args, $this->bounds[0], $this->bounds[2], $this->bounds[1], $this->bounds[3] );
		}
		return array( implode( ' AND ', $sql ), $args );
	}

	/**
	 * ORDER BY clause (from a fixed list, never from input).
	 */
	public function order_by(): string {
		return self::SORTS[ $this->sort ] . ', listing_key ASC';
	}
}
