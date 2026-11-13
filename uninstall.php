<?php
/**
 * Remove everything the plugin stored.
 *
 * @package ResoIdx
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

\ResoIdx\Storage\Schema::drop();

foreach ( array( 'reso_idx_settings', 'reso_idx_cursor', 'reso_idx_log', 'reso_idx_fields', 'reso_idx_sync_lock' ) as $reso_idx_option ) {
	delete_option( $reso_idx_option );
}
wp_clear_scheduled_hook( 'reso_idx_sync' );
wp_clear_scheduled_hook( 'reso_idx_reconcile' );
