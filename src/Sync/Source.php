<?php
/**
 * Where listings come from.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

/**
 * A listing feed: a live RESO server or the bundled demo feed.
 *
 * Records use RESO Data Dictionary field names. Photos are attached under a
 * `Media` key as a list of RESO Media records.
 */
interface Source {

	/**
	 * Records changed after the cursor, oldest first, at most $limit.
	 *
	 * Includes records in any status, so the sync can remove listings that
	 * went off the market.
	 *
	 * @param Cursor $after Last record already processed.
	 * @param int    $limit Batch size.
	 * @return list<array<string, mixed>>
	 */
	public function changes( Cursor $after, int $limit ): array;

	/**
	 * Every ListingKey that is currently displayable, for removing listings the
	 * feed dropped without sending a status change.
	 *
	 * @return list<string>
	 */
	public function active_keys(): array;
}
