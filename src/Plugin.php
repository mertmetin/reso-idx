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
	 * Activation: create the table, schedule the sync, and load the demo feed
	 * so a fresh install shows listings right away.
	 */
	public static function activate(): void {
		Schema::install();
		if ( false === get_option( Settings::OPTION ) ) {
			update_option( Settings::OPTION, Settings::defaults(), false );
		}
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
