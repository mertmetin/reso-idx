<?php
/**
 * WP-CLI commands.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Cli;

use ResoIdx\Settings;
use ResoIdx\Storage\ListingRepository;
use ResoIdx\Sync\SyncService;

/**
 * Sync and inspect RESO IDX listings.
 *
 * ## EXAMPLES
 *
 *     wp reso-idx sync --all
 *     wp reso-idx status
 */
final class Command {

	/**
	 * Pull changes from the feed.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Keep going until the feed is caught up (use for the first import).
	 *
	 * [--reset]
	 * : Clear stored listings and start from the beginning.
	 *
	 * @param list<string>          $args       Positional args.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function sync( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['reset'] ) ) {
			SyncService::reset();
			\WP_CLI::log( 'Cleared stored listings.' );
		}
		$all   = isset( $assoc_args['all'] );
		$total = array(
			'saved'   => 0,
			'removed' => 0,
		);
		do {
			$result = SyncService::sync( 5 );
			if ( null === $result ) {
				$log = SyncService::log_entries()[0] ?? null;
				\WP_CLI::error( $log && ! $log['ok'] ? $log['message'] : 'Another sync is running.' );
			}
			$total['saved']   += $result->saved;
			$total['removed'] += $result->removed;
			\WP_CLI::log( sprintf( 'Saved %d, removed %d, up to %s', $result->saved, $result->removed, $result->cursor->timestamp ) );
		} while ( $all && ! $result->done );

		\WP_CLI::success( sprintf( '%d saved, %d removed. %d listings stored.', $total['saved'], $total['removed'], ( new ListingRepository() )->count() ) );
	}

	/**
	 * Remove listings that are no longer in the feed.
	 */
	public function reconcile(): void {
		$removed = SyncService::reconcile();
		if ( null === $removed ) {
			\WP_CLI::error( SyncService::log_entries()[0]['message'] ?? 'Reconcile failed.' );
		}
		\WP_CLI::success( sprintf( '%d listings removed.', $removed ) );
	}

	/**
	 * Check the feed connection.
	 *
	 * @subcommand test-connection
	 */
	public function test_connection(): void {
		$result = SyncService::test_connection();
		$result['ok'] ? \WP_CLI::success( $result['message'] ) : \WP_CLI::error( $result['message'] );
	}

	/**
	 * Show the feed, listing count and recent runs.
	 */
	public function status(): void {
		$cursor = get_option( SyncService::CURSOR_OPTION );
		\WP_CLI::log( 'Source:    ' . ( Settings::is_demo() ? 'demo feed' : (string) Settings::get( 'endpoint' ) ) );
		\WP_CLI::log( 'Listings:  ' . ( new ListingRepository() )->count() );
		\WP_CLI::log( 'Cursor:    ' . ( is_array( $cursor ) ? $cursor['timestamp'] . ' / ' . $cursor['key'] : 'not started' ) );
		$next = wp_next_scheduled( SyncService::CRON_HOOK );
		\WP_CLI::log( 'Next sync: ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ) );

		$rows = array_map(
			static fn ( array $e ): array => array(
				'when'    => gmdate( 'Y-m-d H:i', $e['time'] ),
				'type'    => $e['type'],
				'ok'      => $e['ok'] ? 'yes' : 'no',
				'message' => $e['message'],
			),
			array_slice( SyncService::log_entries(), 0, 10 )
		);
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'when', 'type', 'ok', 'message' ) );
		}
	}
}
