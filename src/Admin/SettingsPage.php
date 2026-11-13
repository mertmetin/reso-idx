<?php
/**
 * Settings → RESO IDX.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Admin;

use ResoIdx\Frontend\ListingPage;
use ResoIdx\Settings;
use ResoIdx\Storage\ListingRepository;
use ResoIdx\Sync\SyncService;

/**
 * Feed connection, sync controls, display rules and the sync log.
 */
final class SettingsPage {

	private const SLUG       = 'reso-idx';
	private const CAPABILITY = 'manage_options';

	/**
	 * Hook up the screen and its actions.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_reso_idx_save', array( self::class, 'save' ) );
		add_action( 'admin_post_reso_idx_action', array( self::class, 'action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RESO_IDX_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Add the menu item.
	 */
	public static function menu(): void {
		add_options_page( __( 'RESO IDX', 'reso-idx' ), __( 'RESO IDX', 'reso-idx' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param list<string> $links Links.
	 * @return list<string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'reso-idx' ) . '</a>' );
		return $links;
	}

	/**
	 * Save settings.
	 */
	public static function save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'reso-idx' ), 403 );
		}
		check_admin_referer( 'reso_idx_save' );

		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.
		$before = Settings::all();
		$values = self::sanitize( $in );

		Settings::update( $values );

		if ( $before['sync_interval'] !== $values['sync_interval'] ) {
			SyncService::schedule();
		}
		if ( $before['listing_base'] !== $values['listing_base'] ) {
			ListingPage::add_rewrite_rule();
			flush_rewrite_rules();
		}
		// A different feed or scope means the stored listings no longer match.
		$feed_keys = array( 'source', 'endpoint', 'resource', 'scope_filter', 'statuses' );
		$changed   = array_filter( $feed_keys, static fn ( string $k ): bool => (string) $before[ $k ] !== (string) $values[ $k ] );
		if ( array() !== $changed ) {
			SyncService::reset();
			delete_option( SyncService::FIELDS_OPTION );
		}

		self::redirect( array() !== $changed ? 'saved_reset' : 'saved' );
	}

	/**
	 * Clean posted values.
	 *
	 * @param array<string, mixed> $in Posted values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $in ): array {
		$current = Settings::all();
		$text    = static fn ( string $k ): string => isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '';
		$pick    = static fn ( string $k, array $allowed, string $fallback ): string => in_array( $in[ $k ] ?? '', $allowed, true ) ? (string) $in[ $k ] : $fallback;

		// Leaving the token field blank keeps the saved one; "clear" removes it.
		$token = isset( $in['token'] ) ? trim( (string) $in['token'] ) : '';
		if ( '' === $token ) {
			$token = ! empty( $in['token_clear'] ) ? '' : (string) $current['token'];
		}

		$base = sanitize_title( $text( 'listing_base' ) );

		return array(
			'source'           => $pick( 'source', array( 'demo', 'remote' ), 'demo' ),
			'endpoint'         => esc_url_raw( $text( 'endpoint' ), array( 'https', 'http' ) ),
			'token'            => preg_replace( '/\s+/', '', $token ) ?? '',
			'resource'         => preg_match( '/^[A-Za-z]+$/', $text( 'resource' ) ) ? $text( 'resource' ) : 'Property',
			'scope_filter'     => $text( 'scope_filter' ),
			'statuses'         => '' !== $text( 'statuses' ) ? $text( 'statuses' ) : 'Active',
			'media_mode'       => $pick( 'media_mode', array( 'resource', 'expand', 'none' ), 'resource' ),
			'media_filter'     => $text( 'media_filter' ),
			'batch_size'       => max( 10, min( 1000, absint( $in['batch_size'] ?? 200 ) ) ),
			'sync_interval'    => $pick( 'sync_interval', array( 'reso_idx_15min', 'hourly', 'twicedaily', 'daily' ), 'hourly' ),
			'listing_base'     => '' !== $base ? $base : 'listing',
			'per_page'         => max( 3, min( 48, absint( $in['per_page'] ?? 12 ) ) ),
			'disclaimer'       => sanitize_textarea_field( (string) ( $in['disclaimer'] ?? '' ) ),
			'show_attribution' => ! empty( $in['show_attribution'] ),
			'map_enabled'      => ! empty( $in['map_enabled'] ),
		);
	}

	/**
	 * Test connection, sync now, reconcile or reset.
	 */
	public static function action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'reso-idx' ), 403 );
		}
		check_admin_referer( 'reso_idx_action' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		switch ( $do ) {
			case 'test':
				$result = SyncService::test_connection();
				set_transient( 'reso_idx_notice_' . get_current_user_id(), $result, 60 );
				self::redirect( 'tested' );
				break;
			case 'sync':
				SyncService::sync( 10 );
				self::redirect( 'synced' );
				break;
			case 'reconcile':
				SyncService::reconcile();
				self::redirect( 'synced' );
				break;
			case 'reset':
				SyncService::reset();
				self::redirect( 'reset' );
				break;
		}
		self::redirect( '' );
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$s      = Settings::all();
		$repo   = new ListingRepository();
		$log    = SyncService::log_entries();
		$cursor = get_option( SyncService::CURSOR_OPTION );
		$next   = wp_next_scheduled( SyncService::CRON_HOOK );
		$fields = get_option( SyncService::FIELDS_OPTION, array() );
		$source = Settings::token_source();
		$notice = isset( $_GET['reso_idx'] ) ? sanitize_key( wp_unslash( $_GET['reso_idx'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$tested = get_transient( 'reso_idx_notice_' . get_current_user_id() );
		$demo   = 'demo' === $s['source'];
		?>
		<div class="wrap reso-idx-admin">
			<h1><?php esc_html_e( 'RESO IDX', 'reso-idx' ); ?></h1>

			<?php self::notice( $notice, is_array( $tested ) ? $tested : null ); ?>

			<div class="card" style="max-width:none">
				<h2><?php esc_html_e( 'Status', 'reso-idx' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: number of listings, 2: feed name */
						esc_html__( '%1$s listings stored from the %2$s.', 'reso-idx' ),
						'<strong>' . esc_html( number_format_i18n( $repo->count() ) ) . '</strong>',
						$demo ? esc_html__( 'demo feed', 'reso-idx' ) : esc_html__( 'live feed', 'reso-idx' )
					);
					echo ' ';
					if ( is_array( $cursor ) && ! empty( $cursor['timestamp'] ) ) {
						/* translators: %s: timestamp */
						echo esc_html( sprintf( __( 'Synced up to changes made at %s (UTC).', 'reso-idx' ), $cursor['timestamp'] ) ) . ' ';
					}
					if ( $next ) {
						/* translators: %s: human time difference */
						echo esc_html( sprintf( __( 'Next automatic sync in %s.', 'reso-idx' ), human_time_diff( time(), $next ) ) );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:.5rem;flex-wrap:wrap">
					<input type="hidden" name="action" value="reso_idx_action">
					<?php wp_nonce_field( 'reso_idx_action' ); ?>
					<button class="button button-primary" name="do" value="sync"><?php esc_html_e( 'Sync now', 'reso-idx' ); ?></button>
					<?php if ( ! $demo ) : ?>
						<button class="button" name="do" value="test"><?php esc_html_e( 'Test connection', 'reso-idx' ); ?></button>
					<?php endif; ?>
					<button class="button" name="do" value="reconcile"><?php esc_html_e( 'Remove delisted', 'reso-idx' ); ?></button>
					<button class="button button-link-delete" name="do" value="reset" onclick="return confirm('<?php echo esc_js( __( 'Delete all stored listings and sync again from the start?', 'reso-idx' ) ); ?>')"><?php esc_html_e( 'Reset and resync', 'reso-idx' ); ?></button>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="reso_idx_save">
				<?php wp_nonce_field( 'reso_idx_save' ); ?>

				<h2 class="title"><?php esc_html_e( 'Feed', 'reso-idx' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'reso-idx' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="source" value="demo" <?php checked( $s['source'], 'demo' ); ?>> <?php esc_html_e( 'Demo feed (60 fictional listings, no account needed)', 'reso-idx' ); ?></label><br>
								<label><input type="radio" name="source" value="remote" <?php checked( $s['source'], 'remote' ); ?>> <?php esc_html_e( 'RESO Web API server', 'reso-idx' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-endpoint"><?php esc_html_e( 'Endpoint URL', 'reso-idx' ); ?></label></th>
						<td><input id="reso-endpoint" class="regular-text code" type="url" name="endpoint" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://api.example.com/odata/">
						<p class="description"><?php esc_html_e( 'The OData service root from your MLS or data provider.', 'reso-idx' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-token"><?php esc_html_e( 'Access token', 'reso-idx' ); ?></label></th>
						<td>
							<?php if ( in_array( $source, array( 'constant', 'environment' ), true ) ) : ?>
								<p><?php echo esc_html( 'constant' === $source ? __( 'Set by the RESO_IDX_TOKEN constant in wp-config.php.', 'reso-idx' ) : __( 'Set by the RESO_IDX_TOKEN environment variable.', 'reso-idx' ) ); ?></p>
							<?php else : ?>
								<input id="reso-token" class="regular-text code" type="password" name="token" value="" autocomplete="new-password" placeholder="<?php echo 'database' === $source ? esc_attr__( 'Saved. Leave blank to keep it.', 'reso-idx' ) : ''; ?>">
								<?php if ( 'database' === $source ) : ?>
									<label><input type="checkbox" name="token_clear" value="1"> <?php esc_html_e( 'Remove saved token', 'reso-idx' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Never shown again after saving. To keep it out of the database, define RESO_IDX_TOKEN in wp-config.php instead.', 'reso-idx' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-resource"><?php esc_html_e( 'Resource', 'reso-idx' ); ?></label></th>
						<td><input id="reso-resource" class="small-text code" type="text" name="resource" value="<?php echo esc_attr( $s['resource'] ); ?>" style="width:10em">
						<?php if ( ! empty( $fields[ $s['resource'] ] ) ) : ?>
							<p class="description">
								<?php
								/* translators: %d: number of fields */
								echo esc_html( sprintf( __( 'The server offers %d fields; only those the plugin uses are requested.', 'reso-idx' ), count( $fields[ $s['resource'] ] ) ) );
								?>
							</p>
						<?php endif; ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-scope"><?php esc_html_e( 'Scope filter', 'reso-idx' ); ?></label></th>
						<td><input id="reso-scope" class="large-text code" type="text" name="scope_filter" value="<?php echo esc_attr( $s['scope_filter'] ); ?>" placeholder="City eq 'Ottawa'">
						<p class="description"><?php esc_html_e( 'Optional OData $filter that limits which listings are synced, e.g. one city or board area. Leave out status; that is set below.', 'reso-idx' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-statuses"><?php esc_html_e( 'Statuses to show', 'reso-idx' ); ?></label></th>
						<td><input id="reso-statuses" class="regular-text code" type="text" name="statuses" value="<?php echo esc_attr( $s['statuses'] ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated StandardStatus values. Listings in any other status are removed. Most IDX rules allow Active only.', 'reso-idx' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Photos', 'reso-idx' ); ?></th>
						<td>
							<select name="media_mode">
								<option value="resource" <?php selected( $s['media_mode'], 'resource' ); ?>><?php esc_html_e( 'Separate Media requests', 'reso-idx' ); ?></option>
								<option value="expand" <?php selected( $s['media_mode'], 'expand' ); ?>><?php esc_html_e( '$expand=Media', 'reso-idx' ); ?></option>
								<option value="none" <?php selected( $s['media_mode'], 'none' ); ?>><?php esc_html_e( 'No photos', 'reso-idx' ); ?></option>
							</select>
							<p><input class="large-text code" type="text" name="media_filter" value="<?php echo esc_attr( $s['media_filter'] ); ?>" placeholder="ImageSizeDescription eq 'Large'" aria-label="<?php esc_attr_e( 'Media filter', 'reso-idx' ); ?>"></p>
							<p class="description"><?php esc_html_e( 'Optional $filter for Media requests, to fetch one image size instead of every size.', 'reso-idx' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync', 'reso-idx' ); ?></th>
						<td>
							<select name="sync_interval">
								<option value="reso_idx_15min" <?php selected( $s['sync_interval'], 'reso_idx_15min' ); ?>><?php esc_html_e( 'Every 15 minutes', 'reso-idx' ); ?></option>
								<option value="hourly" <?php selected( $s['sync_interval'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'reso-idx' ); ?></option>
								<option value="twicedaily" <?php selected( $s['sync_interval'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice a day', 'reso-idx' ); ?></option>
								<option value="daily" <?php selected( $s['sync_interval'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'reso-idx' ); ?></option>
							</select>
							<label><?php esc_html_e( 'Batch size', 'reso-idx' ); ?> <input class="small-text" type="number" min="10" max="1000" name="batch_size" value="<?php echo esc_attr( (string) $s['batch_size'] ); ?>"></label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Display', 'reso-idx' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="reso-base"><?php esc_html_e( 'Listing URL base', 'reso-idx' ); ?></label></th>
						<td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="reso-base" class="regular-text code" type="text" name="listing_base" value="<?php echo esc_attr( $s['listing_base'] ); ?>" style="width:10em"><code>/{key}/{address}/</code></td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-per-page"><?php esc_html_e( 'Listings per page', 'reso-idx' ); ?></label></th>
						<td><input id="reso-per-page" class="small-text" type="number" min="3" max="48" name="per_page" value="<?php echo esc_attr( (string) $s['per_page'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Compliance', 'reso-idx' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_attribution" value="1" <?php checked( $s['show_attribution'] ); ?>> <?php esc_html_e( 'Show the listing brokerage on every card and listing page', 'reso-idx' ); ?></label><br>
							<label><input type="checkbox" name="map_enabled" value="1" <?php checked( $s['map_enabled'] ); ?>> <?php esc_html_e( 'Show maps (OpenStreetMap)', 'reso-idx' ); ?></label>
							<p class="description"><?php esc_html_e( 'Listings marked InternetEntireListingDisplayYN = false are never stored, and addresses marked InternetAddressDisplayYN = false are hidden, whatever these settings say.', 'reso-idx' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="reso-disclaimer"><?php esc_html_e( 'Disclaimer', 'reso-idx' ); ?></label></th>
						<td><textarea id="reso-disclaimer" class="large-text" rows="3" name="disclaimer"><?php echo esc_textarea( $s['disclaimer'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown under search results and on listing pages, with the time data was last updated. Use the wording your board requires.', 'reso-idx' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Recent runs', 'reso-idx' ); ?></h2>
			<?php if ( array() === $log ) : ?>
				<p><?php esc_html_e( 'No runs yet.', 'reso-idx' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When', 'reso-idx' ); ?></th><th><?php esc_html_e( 'Type', 'reso-idx' ); ?></th><th><?php esc_html_e( 'Result', 'reso-idx' ); ?></th><th><?php esc_html_e( 'Time', 'reso-idx' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 15 ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', (int) $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $entry['type'] ); ?></td>
							<td><?php echo $entry['ok'] ? '' : '<strong style="color:#b32d2e">' . esc_html__( 'Error:', 'reso-idx' ) . '</strong> '; ?><?php echo esc_html( $entry['message'] ); ?></td>
							<td><?php echo esc_html( $entry['seconds'] . 's' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 class="title"><?php esc_html_e( 'Show listings on your site', 'reso-idx' ); ?></h2>
			<p><?php esc_html_e( 'Add the "Listing Search" block to any page, or use the shortcode:', 'reso-idx' ); ?> <code>[reso_idx_search]</code> <code>[reso_idx_search city="Ottawa" type="Condominium" map="no"]</code></p>
		</div>
		<?php
	}

	/**
	 * Admin notice after an action.
	 *
	 * @param string                                $notice Notice key.
	 * @param array{ok: bool, message: string}|null $tested Connection test result.
	 */
	private static function notice( string $notice, ?array $tested ): void {
		$messages = array(
			'saved'       => array( 'success', __( 'Settings saved.', 'reso-idx' ) ),
			'saved_reset' => array( 'warning', __( 'Settings saved. The feed changed, so stored listings were cleared; click "Sync now" or wait for the next automatic sync.', 'reso-idx' ) ),
			'synced'      => array( 'info', __( 'Run finished. See "Recent runs" below.', 'reso-idx' ) ),
			'reset'       => array( 'warning', __( 'Stored listings cleared. The next sync starts from the beginning.', 'reso-idx' ) ),
		);
		if ( 'tested' === $notice && null !== $tested ) {
			$messages['tested'] = array( $tested['ok'] ? 'success' : 'error', $tested['message'] );
		}
		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/**
	 * Settings screen URL.
	 */
	private static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Redirect back with a notice and stop.
	 *
	 * @param string $notice Notice key.
	 */
	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'reso_idx', $notice, self::url() ) );
		exit;
	}
}
