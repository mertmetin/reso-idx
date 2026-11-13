<?php
/**
 * Database table.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Storage;

/**
 * Creates and drops the listings table.
 *
 * Listings live in their own indexed table rather than as posts with meta:
 * price, bedroom and map-bounds searches over thousands of rows stay fast,
 * and a sync doesn't flood wp_posts with revisions and cache invalidations.
 */
final class Schema {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'reso_idx_db_version';

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'reso_idx_listings';
	}

	/**
	 * Create or upgrade the table.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				listing_key varchar(64) NOT NULL,
				listing_id varchar(64) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT '',
				property_type varchar(64) NOT NULL DEFAULT '',
				property_subtype varchar(64) NOT NULL DEFAULT '',
				price bigint(20) unsigned DEFAULT NULL,
				beds tinyint(3) unsigned DEFAULT NULL,
				baths tinyint(3) unsigned DEFAULT NULL,
				living_area varchar(32) NOT NULL DEFAULT '',
				year_built smallint(5) unsigned DEFAULT NULL,
				address varchar(191) NOT NULL DEFAULT '',
				unit varchar(32) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				region varchar(100) NOT NULL DEFAULT '',
				province varchar(32) NOT NULL DEFAULT '',
				postal_code varchar(16) NOT NULL DEFAULT '',
				latitude decimal(10,7) DEFAULT NULL,
				longitude decimal(10,7) DEFAULT NULL,
				address_hidden tinyint(1) NOT NULL DEFAULT 0,
				remarks text NOT NULL,
				office_name varchar(191) NOT NULL DEFAULT '',
				agent_name varchar(191) NOT NULL DEFAULT '',
				photos longtext NOT NULL,
				photo_count smallint(5) unsigned NOT NULL DEFAULT 0,
				virtual_tour text NOT NULL,
				details text NOT NULL,
				listed_at date DEFAULT NULL,
				modified_at datetime NOT NULL,
				synced_at datetime NOT NULL,
				PRIMARY KEY  (listing_key),
				KEY listing_id (listing_id),
				KEY city (city),
				KEY price (price),
				KEY modified_at (modified_at),
				KEY geo (latitude,longitude)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Install when the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drop the table (uninstall only).
	 */
	public static function drop(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall only.
		delete_option( self::DB_VERSION_OPTION );
	}
}
