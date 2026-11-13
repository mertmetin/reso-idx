<?php
/**
 * Runs the sync inside WordPress.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Sync;

use ResoIdx\Api\Client;
use ResoIdx\Api\Metadata;
use ResoIdx\Api\WpTransport;
use ResoIdx\Settings;
use ResoIdx\Storage\ListingRepository;

/**
 * Wires Syncer to WordPress: builds the source from settings, schedules
 * WP-Cron, prevents overlapping runs, saves the cursor and keeps a run log.
 */
final class SyncService {

	public const CRON_HOOK      = 'reso_idx_sync';
	public const RECONCILE_HOOK = 'reso_idx_reconcile';
	public const CURSOR_OPTION  = 'reso_idx_cursor';
	public const LOG_OPTION     = 'reso_idx_log';
	public const FIELDS_OPTION  = 'reso_idx_fields';
	private const LOCK          = 'reso_idx_sync_lock';
	private const LOG_SIZE      = 30;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'cron_sync' ) );
		add_action( self::RECONCILE_HOOK, array( self::class, 'cron_reconcile' ) );
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
	}

	/**
	 * Add a 15-minute schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedules( array $schedules ): array {
		$schedules['reso_idx_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'reso-idx' ),
		);
		return $schedules;
	}

	/**
	 * (Re)schedule cron events to match the settings.
	 */
	public static function schedule(): void {
		$interval = (string) Settings::get( 'sync_interval' );
		$event    = wp_get_scheduled_event( self::CRON_HOOK );
		if ( $event && $event->schedule !== $interval ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$event = false;
		}
		if ( ! $event ) {
			wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RECONCILE_HOOK );
		}
	}

	/**
	 * Remove cron events.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::RECONCILE_HOOK );
	}

	/**
	 * WP-Cron callback.
	 */
	public static function cron_sync(): void {
		self::sync( 5 );
	}

	/**
	 * WP-Cron callback.
	 */
	public static function cron_reconcile(): void {
		self::reconcile();
	}

	/**
	 * Run some sync batches. Returns null if another run holds the lock.
	 *
	 * @param int $max_batches Batches to run.
	 */
	public static function sync( int $max_batches ): ?SyncResult {
		if ( ! self::lock() ) {
			return null;
		}
		$started = microtime( true );
		$repo    = new ListingRepository();
		try {
			$syncer = new Syncer( self::source(), $repo, new Mapper( Settings::statuses() ), array( self::class, 'geocode' ) );
			$result = $syncer->run(
				Cursor::from_array( get_option( self::CURSOR_OPTION ) ),
				max( 10, min( 1000, (int) Settings::get( 'batch_size' ) ) ),
				$max_batches
			);
			update_option( self::CURSOR_OPTION, $result->cursor->to_array(), false );
			$repo->flush_cache();
			self::log(
				'sync',
				true,
				sprintf(
					/* translators: 1: listings saved, 2: listings removed, 3: whether the feed is caught up */
					__( '%1$d saved, %2$d removed. %3$s', 'reso-idx' ),
					$result->saved,
					$result->removed,
					$result->done ? __( 'Up to date.', 'reso-idx' ) : __( 'More changes waiting; the next run continues.', 'reso-idx' )
				),
				$started
			);
			return $result;
		} catch ( \Throwable $e ) {
			self::log( 'sync', false, $e->getMessage(), $started );
			return null;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Remove listings the feed dropped.
	 *
	 * @return int|null Listings removed, or null on error.
	 */
	public static function reconcile(): ?int {
		if ( ! self::lock() ) {
			return null;
		}
		$started = microtime( true );
		$repo    = new ListingRepository();
		try {
			$removed = ( new Syncer( self::source(), $repo, new Mapper( Settings::statuses() ) ) )->reconcile();
			$repo->flush_cache();
			/* translators: %d: listings removed */
			self::log( 'reconcile', true, sprintf( __( '%d listings no longer in the feed were removed.', 'reso-idx' ), $removed ), $started );
			return $removed;
		} catch ( \Throwable $e ) {
			self::log( 'reconcile', false, $e->getMessage(), $started );
			return null;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Start over: clear the cursor and stored listings.
	 */
	public static function reset(): void {
		delete_option( self::CURSOR_OPTION );
		( new ListingRepository() )->truncate();
	}

	/**
	 * Check the connection and record which fields the server offers.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test_connection(): array {
		if ( Settings::is_demo() ) {
			return array(
				'ok'      => true,
				'message' => __( 'Demo feed: no connection needed.', 'reso-idx' ),
			);
		}
		$endpoint = rtrim( (string) Settings::get( 'endpoint' ), '/' ) . '/';
		$resource = (string) Settings::get( 'resource' );
		try {
			$transport = new WpTransport();
			$response  = $transport->get(
				$endpoint . '$metadata',
				array(
					'Accept'        => 'application/xml',
					'Authorization' => 'Bearer ' . Settings::token(),
				)
			);
			if ( 200 !== $response['status'] ) {
				return array(
					'ok'      => false,
					/* translators: %d: HTTP status code */
					'message' => sprintf( __( 'The server answered with HTTP %d. Check the endpoint URL and access token.', 'reso-idx' ), $response['status'] ),
				);
			}
			$fields = array(
				$resource => Metadata::fields( $response['body'], $resource ),
				'Media'   => Metadata::fields( $response['body'], 'Media' ),
			);
			update_option( self::FIELDS_OPTION, $fields, false );

			$client = new Client( $endpoint, Settings::token(), $transport, 1 );
			$query  = \ResoIdx\Api\ODataQuery::create()->where( (string) Settings::get( 'scope_filter' ) )->select( array( 'ListingKey' ) )->top( 1 );
			$sample = $client->page( $resource, $query );

			return array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: number of fields, 2: resource name, 3: "a listing" or "no listings" */
					__( 'Connected. The server offers %1$d %2$s fields, and the scope filter returns %3$s.', 'reso-idx' ),
					count( $fields[ $resource ] ),
					$resource,
					array() === $sample['value'] ? __( 'no listings', 'reso-idx' ) : __( 'listings', 'reso-idx' )
				),
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Coordinates for a listing the feed sent without them.
	 *
	 * Some feeds (e.g. PropTx IDX in Ontario) carry no Latitude/Longitude. The
	 * plugin doesn't bundle a geocoder, since bulk use of free geocoding
	 * services breaks their terms; a site adds one through this filter:
	 *
	 *     add_filter( 'reso_idx_geocode', function ( $point, $listing ) {
	 *         return my_geocoder( $listing['address'], $listing['city'], $listing['postal_code'] ); // [ lat, lng ] or null
	 *     }, 10, 2 );
	 *
	 * The result is stored, so each address is looked up once.
	 *
	 * @param array<string, mixed> $listing Mapped listing.
	 * @return array{0: float, 1: float}|null
	 */
	public static function geocode( array $listing ): ?array {
		$point = apply_filters( 'reso_idx_geocode', null, $listing );
		return is_array( $point ) && 2 === count( $point ) ? array( (float) $point[0], (float) $point[1] ) : null;
	}

	/**
	 * The source configured in settings.
	 *
	 * @throws \RuntimeException When the live feed isn't configured.
	 */
	public static function source(): Source {
		$settings = Settings::all();
		if ( 'demo' === $settings['source'] ) {
			// Site-relative image paths, so the demo survives a change of domain or port.
			return new DemoSource( RESO_IDX_DIR . 'data/demo-listings.json', wp_make_link_relative( RESO_IDX_URL ), Settings::statuses() );
		}
		if ( '' === trim( (string) $settings['endpoint'] ) || '' === Settings::token() ) {
			throw new \RuntimeException( esc_html__( 'Add the endpoint URL and access token in Settings → RESO IDX.', 'reso-idx' ) );
		}
		$fields = get_option( self::FIELDS_OPTION, array() );
		return new RemoteSource(
			new Client( (string) $settings['endpoint'], Settings::token(), new WpTransport() ),
			(string) $settings['resource'],
			(string) $settings['scope_filter'],
			Settings::statuses(),
			(string) $settings['media_mode'],
			(string) ( $settings['media_filter'] ?? '' ),
			is_array( $fields ) ? $fields : array()
		);
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @return list<array{type: string, ok: bool, message: string, time: int, seconds: float}>
	 */
	public static function log_entries(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Add a log entry.
	 *
	 * @param string $type    `sync` or `reconcile`.
	 * @param bool   $ok      Success.
	 * @param string $message Summary.
	 * @param float  $started microtime(true) at start.
	 */
	private static function log( string $type, bool $ok, string $message, float $started ): void {
		$log = self::log_entries();
		array_unshift(
			$log,
			array(
				'type'    => $type,
				'ok'      => $ok,
				'message' => mb_substr( $message, 0, 500 ),
				'time'    => time(),
				'seconds' => round( microtime( true ) - $started, 2 ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/**
	 * Take the lock. A crashed run's lock expires after 10 minutes.
	 */
	private static function lock(): bool {
		// add_option() is atomic: it fails if the row exists.
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK );
		if ( $since < time() - 10 * MINUTE_IN_SECONDS ) {
			update_option( self::LOCK, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Release the lock.
	 */
	private static function unlock(): void {
		delete_option( self::LOCK );
	}
}
