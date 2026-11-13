<?php
/**
 * Sync position.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

/**
 * The last record processed, as (ModificationTimestamp, ListingKey).
 *
 * Using both values (keyset paging) means a batch can end in the middle of a
 * group of records that share one timestamp without skipping or repeating any.
 */
final class Cursor {

	/**
	 * Create a cursor.
	 *
	 * @param string $timestamp ISO 8601 UTC timestamp.
	 * @param string $key       ListingKey of the last record at that timestamp.
	 */
	public function __construct(
		public readonly string $timestamp,
		public readonly string $key = ''
	) {}

	/**
	 * The very beginning: sync everything.
	 */
	public static function start(): self {
		return new self( '1970-01-01T00:00:00Z', '' );
	}

	/**
	 * Restore from storage.
	 *
	 * @param mixed $value Stored array.
	 */
	public static function from_array( $value ): self {
		if ( ! is_array( $value ) || empty( $value['timestamp'] ) ) {
			return self::start();
		}
		return new self( (string) $value['timestamp'], (string) ( $value['key'] ?? '' ) );
	}

	/**
	 * For storage.
	 *
	 * @return array{timestamp: string, key: string}
	 */
	public function to_array(): array {
		return array(
			'timestamp' => $this->timestamp,
			'key'       => $this->key,
		);
	}

	/**
	 * Whether a record at (timestamp, key) comes after this cursor.
	 *
	 * @param string $timestamp Record timestamp.
	 * @param string $key       Record key.
	 */
	public function is_before( string $timestamp, string $key ): bool {
		$order = self::compare_times( $this->timestamp, $timestamp );
		return 0 !== $order ? $order < 0 : strcmp( $this->key, $key ) < 0;
	}

	/**
	 * Compare two timestamps to the microsecond. Returns -1, 0 or 1.
	 *
	 * @param string $a Timestamp.
	 * @param string $b Timestamp.
	 */
	public static function compare_times( string $a, string $b ): int {
		return self::instant( $a ) <=> self::instant( $b );
	}

	/**
	 * Timestamp as [seconds, microseconds], comparable with <=>.
	 *
	 * @param string $timestamp Timestamp.
	 * @return array{0: int, 1: int}
	 */
	private static function instant( string $timestamp ): array {
		try {
			$dt = new \DateTimeImmutable( $timestamp, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return array( 0, 0 );
		}
		return array( (int) $dt->format( 'U' ), (int) $dt->format( 'u' ) );
	}
}
