<?php
/**
 * Bundled demo feed.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

/**
 * Serves fictional RESO records from data/demo-listings.json.
 *
 * The records use real RESO field names and go through the same Syncer and
 * Mapper as a live feed, so the demo exercises the real pipeline. Every
 * address, office and agent in the file is made up.
 */
final class DemoSource implements Source {

	/**
	 * Loaded records.
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private ?array $records = null;

	/**
	 * Set up the feed.
	 *
	 * @param string       $file      Path to the JSON file.
	 * @param string       $asset_url Base URL for demo images.
	 * @param list<string> $statuses  Displayable statuses.
	 */
	public function __construct(
		private string $file,
		private string $asset_url,
		private array $statuses = array( 'Active' )
	) {}

	/**
	 * Records after the cursor, oldest first.
	 *
	 * @param Cursor $after Last record processed.
	 * @param int    $limit Batch size.
	 * @return list<array<string, mixed>>
	 */
	public function changes( Cursor $after, int $limit ): array {
		$rows = array_filter(
			$this->records(),
			static fn ( array $r ): bool => $after->is_before( (string) $r['ModificationTimestamp'], (string) $r['ListingKey'] )
		);
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$order = Cursor::compare_times( (string) $a['ModificationTimestamp'], (string) $b['ModificationTimestamp'] );
				return 0 !== $order ? $order : strcmp( (string) $a['ListingKey'], (string) $b['ListingKey'] );
			}
		);
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Keys of displayable demo listings.
	 *
	 * @return list<string>
	 */
	public function active_keys(): array {
		$statuses = $this->statuses;
		return array_values(
			array_map(
				static fn ( array $r ): string => (string) $r['ListingKey'],
				array_filter( $this->records(), static fn ( array $r ): bool => in_array( $r['StandardStatus'] ?? '', $statuses, true ) )
			)
		);
	}

	/**
	 * Load the file once, point photos at the plugin's assets and make the
	 * listing dates relative to today so the demo always looks current.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records(): array {
		if ( null !== $this->records ) {
			return $this->records;
		}
		$json = is_readable( $this->file ) ? (string) file_get_contents( $this->file ) : '[]'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $json, true );
		$base = rtrim( $this->asset_url, '/' ) . '/';

		$this->records = array();
		foreach ( is_array( $data ) ? $data : array() as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( isset( $record['DaysOnMarket'] ) ) {
				$record['ListingContractDate'] = gmdate( 'Y-m-d', time() - (int) $record['DaysOnMarket'] * DAY_IN_SECONDS );
			}
			foreach ( (array) ( $record['Media'] ?? array() ) as $i => $media ) {
				$record['Media'][ $i ]['MediaURL'] = $base . ltrim( (string) $media['MediaURL'], '/' );
			}
			$this->records[] = $record;
		}
		return $this->records;
	}
}
