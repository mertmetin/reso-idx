<?php
/**
 * Listing card.
 *
 * Override by copying to your-theme/reso-idx/card.php.
 *
 * @package ResoIdx
 *
 * @var array<string, mixed> $listing Listing row.
 */

use ResoIdx\Frontend\View;
use ResoIdx\Settings;

defined( 'ABSPATH' ) || exit;
?>
<article class="reso-idx-card" data-key="<?php echo esc_attr( $listing['listing_key'] ); ?>">
	<a class="reso-idx-card__link" href="<?php echo esc_url( View::url( $listing ) ); ?>">
		<div class="reso-idx-card__media">
			<img src="<?php echo esc_url( View::cover( $listing ) ); ?>" alt="<?php echo esc_attr( View::street( $listing ) ); ?>" loading="lazy" decoding="async" width="600" height="400">
			<?php if ( $listing['photo_count'] > 1 ) : ?>
				<span class="reso-idx-card__count">
					<?php
					/* translators: %d: number of photos */
					echo esc_html( sprintf( _n( '%d photo', '%d photos', $listing['photo_count'], 'reso-idx' ), $listing['photo_count'] ) );
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="reso-idx-card__body">
			<p class="reso-idx-card__price"><?php echo esc_html( View::price( $listing['price'] ) ); ?></p>
			<p class="reso-idx-card__facts"><?php echo esc_html( View::facts( $listing ) ); ?></p>
			<h3 class="reso-idx-card__address"><?php echo esc_html( View::street( $listing ) ); ?></h3>
			<p class="reso-idx-card__locality"><?php echo esc_html( View::locality( $listing ) ); ?></p>
			<?php if ( Settings::get( 'show_attribution' ) && '' !== $listing['office_name'] ) : ?>
				<p class="reso-idx-card__office">
					<?php
					/* translators: %s: listing brokerage name */
					echo esc_html( sprintf( __( 'Listed by %s', 'reso-idx' ), $listing['office_name'] ) );
					?>
				</p>
			<?php endif; ?>
		</div>
	</a>
</article>
