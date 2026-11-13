<?php
/**
 * Listings table access.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Storage;

use ResoIdx\Sync\DemoSource;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; single rows and filter values are object-cached.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the interpolated WHERE/ORDER BY come from SearchCriteria, which only emits fixed SQL with placeholders.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff can't count spread (...$args) replacements; SearchCriteriaTest checks they match.

/**
 * Reads and writes the listings table with $wpdb.
 */
final class ListingRepository implements ListingStore {

	private const CACHE_GROUP = 'reso_idx';

	/**
	 * Insert or replace a listing.
	 *
	 * @param array<string, mixed> $row Output of Mapper::map().
	 */
	public function upsert( array $row ): void {
		global $wpdb;
		$row['photo_count'] = count( $row['photos'] );
		$row['photos']      = (string) wp_json_encode( $row['photos'] );
		$row['details']     = (string) wp_json_encode( $row['details'] );
		$row['synced_at']   = gmdate( 'Y-m-d H:i:s' );
		$wpdb->replace( Schema::table(), $row );
		wp_cache_delete( 'listing_' . $row['listing_key'], self::CACHE_GROUP );
	}

	/**
	 * Remove listings by key.
	 *
	 * @param list<string> $keys Listing keys.
	 */
	public function delete( array $keys ): int {
		global $wpdb;
		$removed = 0;
		foreach ( array_chunk( $keys, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$removed     += (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM %i WHERE listing_key IN ({$placeholders})", Schema::table(), ...$chunk )
			);
			foreach ( $chunk as $key ) {
				wp_cache_delete( 'listing_' . $key, self::CACHE_GROUP );
			}
		}
		return $removed;
	}

	/**
	 * Saved coordinates for a listing.
	 *
	 * @param string $key Listing key.
	 * @return array{0: float, 1: float}|null
	 */
	public function coordinates( string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT latitude, longitude FROM %i WHERE listing_key = %s', Schema::table(), $key ), ARRAY_A );
		return is_array( $row ) && null !== $row['latitude'] ? array( (float) $row['latitude'], (float) $row['longitude'] ) : null;
	}

	/**
	 * Whether any listing has a map location.
	 */
	public function has_coordinates(): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE latitude IS NOT NULL LIMIT 1', Schema::table() ) );
	}

	/**
	 * Every stored key.
	 *
	 * @return list<string>
	 */
	public function keys(): array {
		global $wpdb;
		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT listing_key FROM %i', Schema::table() ) ) );
	}

	/**
	 * Number of stored listings.
	 */
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table() ) );
	}

	/**
	 * Remove everything (switching feeds).
	 */
	public function truncate(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::table() ) );
		$this->flush_cache();
	}

	/**
	 * Drop cached rows and filter values after a sync.
	 */
	public function flush_cache(): void {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
			return;
		}
		wp_cache_delete( 'distinct_city', self::CACHE_GROUP );
		wp_cache_delete( 'distinct_property_type', self::CACHE_GROUP );
	}

	/**
	 * One listing by key.
	 *
	 * @param string $key Listing key.
	 * @return array<string, mixed>|null
	 */
	public function find( string $key ): ?array {
		$cached = wp_cache_get( 'listing_' . $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE listing_key = %s', Schema::table(), $key ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row = self::hydrate( $row );
		wp_cache_set( 'listing_' . $key, $row, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $row;
	}

	/**
	 * Search.
	 *
	 * @param SearchCriteria $criteria Filters, sort and page.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public function search( SearchCriteria $criteria ): array {
		global $wpdb;
		list( $where, $args ) = $criteria->where();
		$order                = $criteria->order_by();
		$offset               = ( $criteria->page - 1 ) * $criteria->per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", Schema::table(), ...$args )
		);
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d", Schema::table(), ...$args, ...array( $criteria->per_page, $offset ) ),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'hydrate' ), $rows ),
			'total' => $total,
			'pages' => (int) ceil( $total / $criteria->per_page ),
		);
	}

	/**
	 * Map pins for every match (lightweight columns only).
	 *
	 * @param SearchCriteria $criteria Filters.
	 * @param int            $limit    Max pins.
	 * @return list<array{key: string, lat: float, lng: float, price: int|null}>
	 */
	public function pins( SearchCriteria $criteria, int $limit = 1000 ): array {
		global $wpdb;
		list( $where, $args ) = $criteria->where();
		$order                = $criteria->order_by();
		$rows                 = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT listing_key, latitude, longitude, price FROM %i WHERE {$where} AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY {$order} LIMIT %d",
				Schema::table(),
				...$args,
				...array( $limit )
			),
			ARRAY_A
		);
		return array_map(
			static fn ( array $r ): array => array(
				'key'   => (string) $r['listing_key'],
				'lat'   => (float) $r['latitude'],
				'lng'   => (float) $r['longitude'],
				'price' => null === $r['price'] ? null : (int) $r['price'],
			),
			$rows
		);
	}

	/**
	 * Distinct values for filter dropdowns.
	 *
	 * @param string $column `city` or `property_type`.
	 * @return list<string>
	 */
	public function distinct( string $column ): array {
		if ( ! in_array( $column, array( 'city', 'property_type' ), true ) ) {
			return array();
		}
		$cache_key = 'distinct_' . $column;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$values = array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT %i FROM %i WHERE %i <> '' ORDER BY %i", $column, Schema::table(), $column, $column ) ) );
		wp_cache_set( $cache_key, $values, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
		return $values;
	}

	/**
	 * Most recent sync time of any row, for the "last updated" notice.
	 */
	public function last_synced(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(synced_at) FROM %i', Schema::table() ) );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Decode JSON columns and cast numbers.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$photos = json_decode( (string) $row['photos'], true );
		$detail = json_decode( (string) $row['details'], true );

		$row['photos']         = is_array( $photos )
			? array_map( static fn ( $url ): string => DemoSource::photo_url( (string) $url, RESO_IDX_URL ), $photos )
			: array();
		$row['details']        = is_array( $detail ) ? $detail : array();
		$row['price']          = null === $row['price'] ? null : (int) $row['price'];
		$row['beds']           = null === $row['beds'] ? null : (int) $row['beds'];
		$row['baths']          = null === $row['baths'] ? null : (int) $row['baths'];
		$row['year_built']     = null === $row['year_built'] ? null : (int) $row['year_built'];
		$row['latitude']       = null === $row['latitude'] ? null : (float) $row['latitude'];
		$row['longitude']      = null === $row['longitude'] ? null : (float) $row['longitude'];
		$row['address_hidden'] = (bool) $row['address_hidden'];
		$row['photo_count']    = (int) $row['photo_count'];
		return $row;
	}
}
