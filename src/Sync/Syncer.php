<?php
/**
 * Incremental sync.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

use ResoIdx\Storage\ListingStore;

/**
 * Moves changes from a Source into a ListingStore.
 *
 * No WordPress calls in here, so the sync rules are unit-tested directly.
 */
final class Syncer {

	/**
	 * If the feed suddenly reports far fewer listings than are stored, treat it
	 * as a feed problem rather than a mass delisting and keep the site as is.
	 */
	private const RECONCILE_MIN_RATIO = 0.5;

	/**
	 * Looks up coordinates for listings the feed sends without them.
	 *
	 * @var (callable(array<string, mixed>): (array{0: float, 1: float}|null))|null
	 */
	private $geocoder;

	/**
	 * Set up the sync.
	 *
	 * @param Source        $source   Listing feed.
	 * @param ListingStore  $store    Storage.
	 * @param Mapper        $mapper   Record mapper with display rules.
	 * @param callable|null $geocoder Called with a mapped row that has an address but no coordinates.
	 */
	public function __construct(
		private Source $source,
		private ListingStore $store,
		private Mapper $mapper,
		?callable $geocoder = null
	) {
		$this->geocoder = $geocoder;
	}

	/**
	 * Process up to $max_batches batches after the cursor.
	 *
	 * Displayable records are saved; anything else (sold, expired, withdrawn,
	 * or opted out of internet display) is removed.
	 *
	 * @param Cursor $cursor      Where the last run stopped.
	 * @param int    $batch_size  Records per request.
	 * @param int    $max_batches Batches before handing back (keeps a cron run short).
	 */
	public function run( Cursor $cursor, int $batch_size, int $max_batches = 5 ): SyncResult {
		$saved   = 0;
		$removed = 0;
		$done    = false;

		for ( $i = 0; $i < $max_batches; $i++ ) {
			$records = $this->source->changes( $cursor, $batch_size );
			if ( array() === $records ) {
				$done = true;
				break;
			}

			$to_remove = array();
			foreach ( $records as $record ) {
				$row = $this->mapper->map( $record );
				if ( null === $row ) {
					$to_remove[] = (string) $record['ListingKey'];
				} else {
					$this->store->upsert( $this->locate( $row ) );
					++$saved;
				}
				$cursor = new Cursor( (string) $record['ModificationTimestamp'], (string) $record['ListingKey'] );
			}
			if ( array() !== $to_remove ) {
				$removed += $this->store->delete( $to_remove );
			}
			// A short batch doesn't mean the feed is caught up: a source may drop
			// records the cursor already covers. Only an empty batch means done.
		}

		return new SyncResult( $cursor, $saved, $removed, $done );
	}

	/**
	 * Fill in missing coordinates: keep the ones already stored, otherwise ask
	 * the geocoder. Never for listings whose address is withheld.
	 *
	 * @param array<string, mixed> $row Mapped listing.
	 * @return array<string, mixed>
	 */
	private function locate( array $row ): array {
		if ( null !== $row['latitude'] || $row['address_hidden'] || '' === $row['address'] ) {
			return $row;
		}
		$point = $this->store->coordinates( (string) $row['listing_key'] );
		if ( null === $point && null !== $this->geocoder ) {
			$point = ( $this->geocoder )( $row );
		}
		if ( null !== $point ) {
			$row['latitude']  = (float) $point[0];
			$row['longitude'] = (float) $point[1];
		}
		return $row;
	}

	/**
	 * Remove stored listings the feed no longer offers.
	 *
	 * Some feeds simply stop returning a listing when it leaves the market or
	 * the brokerage opts out, with no status change for `run()` to see.
	 *
	 * @return int Listings removed.
	 * @throws \RuntimeException When the feed looks broken, to protect the site.
	 */
	public function reconcile(): int {
		$stored = $this->store->keys();
		if ( array() === $stored ) {
			return 0;
		}
		$active = $this->source->active_keys();
		if ( count( $active ) < count( $stored ) * self::RECONCILE_MIN_RATIO ) {
			throw new \RuntimeException(
				sprintf(
					'Reconcile skipped: the feed returned %d listings but %d are stored. Check the feed before removing anything.',
					count( $active ),
					count( $stored )
				)
			);
		}
		$gone = array_values( array_diff( $stored, $active ) );
		return array() === $gone ? 0 : $this->store->delete( $gone );
	}
}
