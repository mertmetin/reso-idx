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
 *
 * Photos are stored as `reso-idx-demo://file.svg` and turned into real URLs
 * only when a page is rendered (see photo_url()). Storing a full URL would
 * break the images whenever the site moves, e.g. between WordPress
 * Playground sessions, which run under a different path each time.
 */
final class DemoSource implements Source {

	public const PHOTO_SCHEME = 'reso-idx-demo://';

	/**
	 * Loaded records.
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private ?array $records = null;

	/**
	 * Set up the feed.
	 *
	 * @param string       $file     Path to the JSON file.
	 * @param list<string> $statuses Displayable statuses.
	 */
	public function __construct(
		private string $file,
		private array $statuses = array( 'Active' )
	) {}

	/**
	 * Turn a stored demo photo reference into a URL under the plugin folder.
	 * Any other URL is returned unchanged.
	 *
	 * @param string $url      Stored photo URL.
	 * @param string $base_url The plugin's URL, ending in a slash.
	 */
	public static function photo_url( string $url, string $base_url ): string {
		if ( ! str_starts_with( $url, self::PHOTO_SCHEME ) ) {
			return $url;
		}
		return $base_url . 'assets/demo/' . rawurlencode( basename( substr( $url, strlen( self::PHOTO_SCHEME ) ) ) );
	}

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
	 * Load the file once, mark photos as demo images and make the listing
	 * dates relative to today so the demo always looks current.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records(): array {
		if ( null !== $this->records ) {
			return $this->records;
		}
		$json = is_readable( $this->file ) ? (string) file_get_contents( $this->file ) : '[]'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $json, true );

		$this->records = array();
		foreach ( is_array( $data ) ? $data : array() as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( isset( $record['DaysOnMarket'] ) ) {
				$record['ListingContractDate'] = gmdate( 'Y-m-d', time() - (int) $record['DaysOnMarket'] * DAY_IN_SECONDS );
			}
			foreach ( (array) ( $record['Media'] ?? array() ) as $i => $media ) {
				$record['Media'][ $i ]['MediaURL'] = self::PHOTO_SCHEME . basename( (string) $media['MediaURL'] );
			}
			$this->records[] = $record;
		}
		return $this->records;
	}
}
