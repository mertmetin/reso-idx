<?php
/**
 * Listing detail content.
 *
 * Override by copying to your-theme/reso-idx/listing.php.
 *
 * @package ResoIdx
 *
 * @var array<string, mixed> $listing Listing row.
 */

use ResoIdx\Frontend\SearchBlock;
use ResoIdx\Frontend\View;
use ResoIdx\Settings;

defined( 'ABSPATH' ) || exit;

$reso_idx_labels = array(
	'style'       => __( 'Style', 'reso-idx' ),
	'heating'     => __( 'Heating', 'reso-idx' ),
	'cooling'     => __( 'Cooling', 'reso-idx' ),
	'parking'     => __( 'Parking spaces', 'reso-idx' ),
	'lot'         => __( 'Lot size', 'reso-idx' ),
	'taxes'       => __( 'Property taxes (yearly)', 'reso-idx' ),
	'association' => __( 'Condo / HOA fee (monthly)', 'reso-idx' ),
);
$reso_idx_money  = array( 'taxes', 'association' );
$reso_idx_photos = $listing['photos'] ? $listing['photos'] : array( View::cover( $listing ) );
?>
<article class="reso-idx-listing">
	<div class="reso-idx-gallery" data-count="<?php echo esc_attr( (string) count( $reso_idx_photos ) ); ?>">
		<div class="reso-idx-gallery__track">
			<?php foreach ( $reso_idx_photos as $reso_idx_i => $reso_idx_photo ) : ?>
				<img src="<?php echo esc_url( $reso_idx_photo ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: 1: address, 2: photo number */ __( '%1$s, photo %2$d', 'reso-idx' ), View::street( $listing ), $reso_idx_i + 1 ) ); ?>" <?php echo 0 === $reso_idx_i ? 'fetchpriority="high"' : 'loading="lazy"'; ?> decoding="async" width="1200" height="800">
			<?php endforeach; ?>
		</div>
		<?php if ( count( $reso_idx_photos ) > 1 ) : ?>
			<button type="button" class="reso-idx-gallery__nav reso-idx-gallery__nav--prev" aria-label="<?php esc_attr_e( 'Previous photo', 'reso-idx' ); ?>">‹</button>
			<button type="button" class="reso-idx-gallery__nav reso-idx-gallery__nav--next" aria-label="<?php esc_attr_e( 'Next photo', 'reso-idx' ); ?>">›</button>
			<p class="reso-idx-gallery__counter" aria-live="polite">1 / <?php echo esc_html( (string) count( $reso_idx_photos ) ); ?></p>
		<?php endif; ?>
	</div>

	<header class="reso-idx-listing__header">
		<div>
			<h1 class="reso-idx-listing__address"><?php echo esc_html( View::street( $listing ) ); ?></h1>
			<p class="reso-idx-listing__locality"><?php echo esc_html( View::locality( $listing ) . ( $listing['postal_code'] && ! $listing['address_hidden'] ? ' ' . $listing['postal_code'] : '' ) ); ?></p>
		</div>
		<div class="reso-idx-listing__price-wrap">
			<p class="reso-idx-listing__price"><?php echo esc_html( View::price( $listing['price'] ) ); ?></p>
			<p class="reso-idx-listing__facts"><?php echo esc_html( View::facts( $listing ) ); ?></p>
		</div>
	</header>

	<div class="reso-idx-listing__columns">
		<div class="reso-idx-listing__main">
			<?php if ( '' !== $listing['remarks'] ) : ?>
				<section>
					<h2><?php esc_html_e( 'About this home', 'reso-idx' ); ?></h2>
					<?php echo wp_kses_post( wpautop( esc_html( $listing['remarks'] ) ) ); ?>
				</section>
			<?php endif; ?>

			<section>
				<h2><?php esc_html_e( 'Details', 'reso-idx' ); ?></h2>
				<dl class="reso-idx-details">
					<div><dt><?php esc_html_e( 'MLS® number', 'reso-idx' ); ?></dt><dd><?php echo esc_html( $listing['listing_id'] ); ?></dd></div>
					<?php if ( '' !== $listing['property_subtype'] || '' !== $listing['property_type'] ) : ?>
						<div><dt><?php esc_html_e( 'Type', 'reso-idx' ); ?></dt><dd><?php echo esc_html( $listing['property_subtype'] ? $listing['property_subtype'] : $listing['property_type'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( null !== $listing['year_built'] ) : ?>
						<div><dt><?php esc_html_e( 'Year built', 'reso-idx' ); ?></dt><dd><?php echo esc_html( (string) $listing['year_built'] ); ?></dd></div>
					<?php endif; ?>
					<?php foreach ( $reso_idx_labels as $reso_idx_key => $reso_idx_label ) : ?>
						<?php if ( isset( $listing['details'][ $reso_idx_key ] ) ) : ?>
							<div>
								<dt><?php echo esc_html( $reso_idx_label ); ?></dt>
								<dd><?php echo esc_html( in_array( $reso_idx_key, $reso_idx_money, true ) ? View::price( (int) $listing['details'][ $reso_idx_key ] ) : $listing['details'][ $reso_idx_key ] ); ?></dd>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( $listing['listed_at'] ) : ?>
						<div><dt><?php esc_html_e( 'Listed', 'reso-idx' ); ?></dt><dd><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $listing['listed_at'] ) ) ); ?></dd></div>
					<?php endif; ?>
				</dl>
			</section>

			<?php if ( Settings::get( 'map_enabled' ) && null !== $listing['latitude'] ) : ?>
				<section>
					<h2><?php esc_html_e( 'Location', 'reso-idx' ); ?></h2>
					<div class="reso-idx-listing__map" data-lat="<?php echo esc_attr( (string) $listing['latitude'] ); ?>" data-lng="<?php echo esc_attr( (string) $listing['longitude'] ); ?>" role="region" aria-label="<?php esc_attr_e( 'Map', 'reso-idx' ); ?>"></div>
				</section>
			<?php endif; ?>
		</div>

		<aside class="reso-idx-listing__aside">
			<div class="reso-idx-panel">
				<?php if ( Settings::get( 'show_attribution' ) && '' !== $listing['office_name'] ) : ?>
					<p class="reso-idx-panel__label"><?php esc_html_e( 'Listing brokerage', 'reso-idx' ); ?></p>
					<p class="reso-idx-panel__office"><?php echo esc_html( $listing['office_name'] ); ?></p>
					<?php if ( '' !== $listing['agent_name'] ) : ?>
						<p class="reso-idx-panel__agent"><?php echo esc_html( $listing['agent_name'] ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $listing['virtual_tour'] ) : ?>
					<p><a class="reso-idx-button reso-idx-button--outline" href="<?php echo esc_url( $listing['virtual_tour'] ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Virtual tour', 'reso-idx' ); ?></a></p>
				<?php endif; ?>
				<?php
				/**
				 * Add a contact form or call-to-action under the brokerage panel.
				 *
				 * @param array $listing Listing row.
				 */
				do_action( 'reso_idx_listing_aside', $listing );
				?>
			</div>
		</aside>
	</div>

	<?php echo SearchBlock::disclaimer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template. ?>
</article>
