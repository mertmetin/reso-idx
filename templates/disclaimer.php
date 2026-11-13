<?php
/**
 * MLS disclaimer and freshness notice.
 *
 * Most boards require a disclaimer and the date the data was last updated
 * wherever listings appear. Override by copying to your-theme/reso-idx/disclaimer.php.
 *
 * @package ResoIdx
 *
 * @var string      $disclaimer  Disclaimer text from settings.
 * @var string|null $last_synced Last sync time (UTC, MySQL format).
 */

defined( 'ABSPATH' ) || exit;
?>
<footer class="reso-idx-disclaimer">
	<?php if ( '' !== $disclaimer ) : ?>
		<p><?php echo esc_html( $disclaimer ); ?></p>
	<?php endif; ?>
	<?php if ( $last_synced ) : ?>
		<p>
			<?php
			/* translators: %s: date and time */
			echo esc_html( sprintf( __( 'Listing data last updated %s.', 'reso-idx' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $last_synced . ' UTC' ) ) ) );
			?>
		</p>
	<?php endif; ?>
</footer>
