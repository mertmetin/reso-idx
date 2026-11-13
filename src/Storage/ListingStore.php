<?php
/**
 * Storage contract used by the sync.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Storage;

/**
 * Where synced listings are written. The WordPress implementation is
 * ListingRepository; tests use an in-memory one.
 */
interface ListingStore {

	/**
	 * Insert or replace a mapped listing.
	 *
	 * @param array<string, mixed> $row Output of Mapper::map().
	 */
	public function upsert( array $row ): void;

	/**
	 * Remove listings by key.
	 *
	 * @param list<string> $keys Listing keys.
	 * @return int Rows removed.
	 */
	public function delete( array $keys ): int;

	/**
	 * Saved coordinates for a listing, so a geocoded location is kept between syncs.
	 *
	 * @param string $key Listing key.
	 * @return array{0: float, 1: float}|null
	 */
	public function coordinates( string $key ): ?array;

	/**
	 * Every stored key.
	 *
	 * @return list<string>
	 */
	public function keys(): array;

	/**
	 * Number of stored listings.
	 */
	public function count(): int;
}
