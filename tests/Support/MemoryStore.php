<?php
/**
 * In-memory listing store.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Support;

use ResoIdx\Storage\ListingStore;

/**
 * Keeps rows in an array, keyed by listing key.
 */
final class MemoryStore implements ListingStore {

	/**
	 * Stored rows.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * How many times each key was written.
	 *
	 * @var array<string, int>
	 */
	public array $writes = array();

	public function upsert( array $row ): void {
		$this->rows[ $row['listing_key'] ] = $row;
		$this->writes[ $row['listing_key'] ] = ( $this->writes[ $row['listing_key'] ] ?? 0 ) + 1;
	}

	public function delete( array $keys ): int {
		$n = 0;
		foreach ( $keys as $key ) {
			if ( isset( $this->rows[ $key ] ) ) {
				unset( $this->rows[ $key ] );
				++$n;
			}
		}
		return $n;
	}

	public function coordinates( string $key ): ?array {
		$row = $this->rows[ $key ] ?? null;
		return $row && null !== $row['latitude'] ? array( $row['latitude'], $row['longitude'] ) : null;
	}

	public function keys(): array {
		return array_map( 'strval', array_keys( $this->rows ) );
	}

	public function count(): int {
		return count( $this->rows );
	}
}
