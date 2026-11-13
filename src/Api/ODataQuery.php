<?php
/**
 * Small immutable builder for OData query strings.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Api;

/**
 * Builds `$filter`, `$select`, `$orderby`, `$top`, `$skip` and `$expand`
 * parameters for a RESO Web API request.
 */
final class ODataQuery {

	/**
	 * Filter clauses, joined with `and`.
	 *
	 * @var list<string>
	 */
	private array $filters = array();

	/**
	 * Fields to select.
	 *
	 * @var list<string>
	 */
	private array $select = array();

	/**
	 * Order by clause.
	 *
	 * @var string
	 */
	private string $orderby = '';

	/**
	 * Page size.
	 *
	 * @var int|null
	 */
	private ?int $top = null;

	/**
	 * Offset.
	 *
	 * @var int|null
	 */
	private ?int $skip = null;

	/**
	 * Navigation property to expand.
	 *
	 * @var string
	 */
	private string $expand = '';

	/**
	 * Start a new query.
	 */
	public static function create(): self {
		return new self();
	}

	/**
	 * Add a raw filter clause. Empty clauses are ignored.
	 *
	 * @param string $clause OData filter expression.
	 */
	public function where( string $clause ): self {
		$clause = trim( $clause );
		if ( '' === $clause ) {
			return $this;
		}
		$copy            = clone $this;
		$copy->filters[] = $clause;
		return $copy;
	}

	/**
	 * Add `Field eq 'value'` (or `or` across several values).
	 *
	 * @param string       $field  Field name.
	 * @param list<string> $values Allowed values.
	 */
	public function where_in( string $field, array $values ): self {
		if ( array() === $values ) {
			return $this;
		}
		self::assert_field( $field );
		$parts = array_map(
			static fn ( string $v ): string => $field . ' eq ' . self::literal( $v ),
			$values
		);
		return $this->where( count( $parts ) > 1 ? '(' . implode( ' or ', $parts ) . ')' : $parts[0] );
	}

	/**
	 * Fields to return.
	 *
	 * @param list<string> $fields Field names.
	 */
	public function select( array $fields ): self {
		array_walk( $fields, array( self::class, 'assert_field' ) );
		$copy         = clone $this;
		$copy->select = array_values( array_unique( $fields ) );
		return $copy;
	}

	/**
	 * Sort order, e.g. `ModificationTimestamp,ListingKey`.
	 *
	 * @param string $orderby OData orderby expression.
	 */
	public function order_by( string $orderby ): self {
		$copy          = clone $this;
		$copy->orderby = $orderby;
		return $copy;
	}

	/**
	 * Page size.
	 *
	 * @param int $top Number of records.
	 */
	public function top( int $top ): self {
		$copy      = clone $this;
		$copy->top = max( 1, $top );
		return $copy;
	}

	/**
	 * Offset.
	 *
	 * @param int $skip Number of records to skip.
	 */
	public function skip( int $skip ): self {
		$copy       = clone $this;
		$copy->skip = max( 0, $skip );
		return $copy;
	}

	/**
	 * Navigation property to expand, e.g. `Media`.
	 *
	 * @param string $expand Expand expression.
	 */
	public function expand( string $expand ): self {
		$copy         = clone $this;
		$copy->expand = $expand;
		return $copy;
	}

	/**
	 * The page size, if set.
	 */
	public function get_top(): ?int {
		return $this->top;
	}

	/**
	 * The query as request parameters.
	 *
	 * @return array<string, string>
	 */
	public function to_params(): array {
		$params = array();
		if ( array() !== $this->filters ) {
			$several           = count( $this->filters ) > 1;
			$params['$filter'] = implode(
				' and ',
				array_map(
					static fn ( string $f ): string => $several && ! self::is_wrapped( $f ) ? '(' . $f . ')' : $f,
					$this->filters
				)
			);
		}
		if ( array() !== $this->select ) {
			$params['$select'] = implode( ',', $this->select );
		}
		if ( '' !== $this->orderby ) {
			$params['$orderby'] = $this->orderby;
		}
		if ( null !== $this->top ) {
			$params['$top'] = (string) $this->top;
		}
		if ( null !== $this->skip && $this->skip > 0 ) {
			$params['$skip'] = (string) $this->skip;
		}
		if ( '' !== $this->expand ) {
			$params['$expand'] = $this->expand;
		}
		return $params;
	}

	/**
	 * The query string, with `$` and spaces encoded the way OData servers expect.
	 */
	public function to_query_string(): string {
		return http_build_query( $this->to_params(), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Quote a string literal: `O'Brien` becomes `'O''Brien'`.
	 *
	 * @param string $value Raw value.
	 */
	public static function literal( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * Format a timestamp for OData filters (UTC, ISO 8601, no quotes).
	 *
	 * @param string $timestamp Any strtotime-readable timestamp.
	 */
	public static function datetime( string $timestamp ): string {
		$dt = new \DateTimeImmutable( $timestamp, new \DateTimeZone( 'UTC' ) );
		$dt = $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		// Keep fractional seconds when present, so `eq` matches the stored value exactly.
		return '000000' === $dt->format( 'u' ) ? $dt->format( 'Y-m-d\TH:i:s\Z' ) : rtrim( $dt->format( 'Y-m-d\TH:i:s.u' ), '0' ) . 'Z';
	}

	/**
	 * Guard against injecting expressions through field names.
	 *
	 * @param string $field Field name.
	 * @throws \InvalidArgumentException When the name is not a plain identifier.
	 */
	private static function assert_field( string $field ): void {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $field ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid OData field name: %s', esc_html( $field ) ) );
		}
	}

	/**
	 * Whether the clause is already one parenthesised group.
	 *
	 * @param string $clause Filter clause.
	 */
	private static function is_wrapped( string $clause ): bool {
		if ( ! str_starts_with( $clause, '(' ) || ! str_ends_with( $clause, ')' ) ) {
			return false;
		}
		$depth = 0;
		$len   = strlen( $clause );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '(' === $clause[ $i ] ) {
				++$depth;
			} elseif ( ')' === $clause[ $i ] ) {
				--$depth;
				if ( 0 === $depth && $i < $len - 1 ) {
					return false;
				}
			}
		}
		return true;
	}
}
