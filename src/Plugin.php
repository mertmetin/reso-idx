<?php
/**
 * Plugin bootstrap.
 *
 * @package ResoIdx
 */

namespace ResoIdx;

use ResoIdx\Admin\SettingsPage;
use ResoIdx\Frontend\Assets;
use ResoIdx\Frontend\ListingPage;
use ResoIdx\Frontend\SearchBlock;
use ResoIdx\Rest\ListingsController;
use ResoIdx\Storage\Schema;
use ResoIdx\Sync\SyncService;

/**
 * Registers every part of the plugin.
 */
final class Plugin {

	/**
	 * Hook everything up.
	 */
	public static function boot(): void {
		Schema::maybe_upgrade();
		self::maybe_refresh_demo();
		SyncService::register();
		Assets::register();
		ListingPage::register();
		SearchBlock::register();
		add_action( 'rest_api_init', array( new ListingsController(), 'register_routes' ) );

		if ( is_admin() ) {
			SettingsPage::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'reso-idx', Cli\Command::class );
		}
	}

	/**
	 * After an update, reload the demo listings so they pick up changes to the
	 * demo data (1.0.0 photo URLs broke when a site moved; 1.0.2 uses real photos).
	 */
	private static function maybe_refresh_demo(): void {
		if ( get_option( 'reso_idx_version' ) === RESO_IDX_VERSION ) {
			return;
		}
		update_option( 'reso_idx_version', RESO_IDX_VERSION, false );
		if ( Settings::is_demo() && false !== get_option( Settings::OPTION ) ) {
			SyncService::reset();
			SyncService::sync( 10 );
		}
	}

	/**
	 * Activation: create the table, schedule the sync, and load the demo feed
	 * so a fresh install shows listings right away.
	 */
	public static function activate(): void {
		Schema::install();
		if ( false === get_option( Settings::OPTION ) ) {
			update_option( Settings::OPTION, Settings::defaults(), false );
		}
		update_option( 'reso_idx_version', RESO_IDX_VERSION, false );
		ListingPage::add_rewrite_rule();
		flush_rewrite_rules();
		SyncService::schedule();
		if ( Settings::is_demo() ) {
			SyncService::sync( 10 );
		}
	}

	/**
	 * Deactivation: stop cron and remove the rewrite rule. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		SyncService::unschedule();
		flush_rewrite_rules();
	}
}
