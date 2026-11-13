<?php
/**
 * Source backed by an array, with a configurable "server".
 *
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Support;

use ResoIdx\Sync\Cursor;
use ResoIdx\Sync\Source;

/**
 * Serves records like a RESO server would, ordered by (timestamp, key).
 */
class ArraySource implements Source {

	/**
	 * Create the source.
	 *
	 * @param list<array<string, mixed>> $records     Records.
	 * @param list<string>|null          $active_keys Override for active_keys().
	 */
	public function __construct( public array $records, public ?array $active_keys = null ) {}

	public function changes( Cursor $after, int $limit ): array {
		$rows = array_values( array_filter( $this->records, static fn ( $r ) => $after->is_before( $r['ModificationTimestamp'], $r['ListingKey'] ) ) );
		usort(
			$rows,
			static fn ( $a, $b ) => array( strtotime( $a['ModificationTimestamp'] ), $a['ListingKey'] ) <=> array( strtotime( $b['ModificationTimestamp'] ), $b['ListingKey'] )
		);
		return array_slice( $rows, 0, $limit );
	}

	public function active_keys(): array {
		if ( null !== $this->active_keys ) {
			return $this->active_keys;
		}
		return array_values( array_map( static fn ( $r ) => $r['ListingKey'], array_filter( $this->records, static fn ( $r ) => 'Active' === $r['StandardStatus'] ) ) );
	}
}
