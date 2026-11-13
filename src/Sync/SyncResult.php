<?php
/**
 * Outcome of a sync run.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

/**
 * Counts and the cursor to resume from.
 */
final class SyncResult {

	/**
	 * Create a result.
	 *
	 * @param Cursor $cursor  Resume point.
	 * @param int    $saved   Listings added or updated.
	 * @param int    $removed Listings removed.
	 * @param bool   $done    True when the feed had no more changes.
	 */
	public function __construct(
		public readonly Cursor $cursor,
		public readonly int $saved,
		public readonly int $removed,
		public readonly bool $done
	) {}
}
