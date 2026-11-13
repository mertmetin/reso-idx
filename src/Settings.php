<?php
/**
 * Plugin settings, stored in a single option.
 *
 * @package ResoIdx
 */

namespace ResoIdx;

/**
 * Typed access to the `reso_idx_settings` option.
 *
 * The access token can also come from the `RESO_IDX_TOKEN` constant (wp-config.php)
 * or environment variable, so it never has to be stored in the database.
 */
final class Settings {

	public const OPTION = 'reso_idx_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'source'           => 'demo',
			'endpoint'         => '',
			'token'            => '',
			'resource'         => 'Property',
			'scope_filter'     => '',
			'statuses'         => 'Active',
			'media_mode'       => 'resource',
			'media_filter'     => '',
			'batch_size'       => 200,
			'sync_interval'    => 'hourly',
			'listing_base'     => 'listing',
			'per_page'         => 12,
			'disclaimer'       => 'Listing information is deemed reliable but not guaranteed. Data provided by the participating brokerages through the MLS® System.',
			'show_attribution' => true,
			'map_enabled'      => true,
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Update some settings, keeping the rest.
	 *
	 * @param array<string, mixed> $values New values.
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * The access token: constant, then environment, then the saved option.
	 */
	public static function token(): string {
		if ( defined( 'RESO_IDX_TOKEN' ) && '' !== (string) constant( 'RESO_IDX_TOKEN' ) ) {
			return (string) constant( 'RESO_IDX_TOKEN' );
		}
		$env = getenv( 'RESO_IDX_TOKEN' );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		return (string) self::get( 'token' );
	}

	/**
	 * Where the token comes from, for the settings screen.
	 */
	public static function token_source(): string {
		if ( defined( 'RESO_IDX_TOKEN' ) && '' !== (string) constant( 'RESO_IDX_TOKEN' ) ) {
			return 'constant';
		}
		$env = getenv( 'RESO_IDX_TOKEN' );
		if ( is_string( $env ) && '' !== $env ) {
			return 'environment';
		}
		return '' !== (string) self::get( 'token' ) ? 'database' : 'none';
	}

	/**
	 * Whether the demo feed is in use.
	 */
	public static function is_demo(): bool {
		return 'demo' === self::get( 'source' );
	}

	/**
	 * Listing statuses to keep, as a list.
	 *
	 * @return list<string>
	 */
	public static function statuses(): array {
		$parts = array_map( 'trim', explode( ',', (string) self::get( 'statuses' ) ) );
		return array_values( array_filter( $parts, static fn ( $s ) => '' !== $s ) );
	}
}
