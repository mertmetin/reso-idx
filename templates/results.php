<?php
/**
 * Search results: count, cards and pagination.
 *
 * Rendered on page load and again by the REST API when filters change.
 * Override by copying to your-theme/reso-idx/results.php.
 *
 * @package ResoIdx
 *
 * @var array{items: list<array<string, mixed>>, total: int, pages: int} $results Search results.
 * @var \ResoIdx\Storage\SearchCriteria                                   $criteria Current filters.
 * @var callable(int): string                                             $page_url URL for a page number.
 */

use ResoIdx\Frontend\View;

defined( 'ABSPATH' ) || exit;
?>
<p class="reso-idx-results__count">
	<?php
	/* translators: %s: number of listings */
	echo esc_html( sprintf( _n( '%s home for sale', '%s homes for sale', $results['total'], 'reso-idx' ), number_format_i18n( $results['total'] ) ) );
	?>
</p>

<?php if ( array() === $results['items'] ) : ?>
	<p class="reso-idx-results__empty"><?php esc_html_e( 'No listings match these filters. Try a wider price range or fewer bedrooms.', 'reso-idx' ); ?></p>
<?php else : ?>
	<div class="reso-idx-grid">
		<?php
		foreach ( $results['items'] as $listing ) {
			echo View::render( 'card', array( 'listing' => $listing ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes its output.
		}
		?>
	</div>
<?php endif; ?>

<?php if ( $results['pages'] > 1 ) : ?>
	<nav class="reso-idx-pagination" aria-label="<?php esc_attr_e( 'Listing pages', 'reso-idx' ); ?>">
		<?php if ( $criteria->page > 1 ) : ?>
			<a class="reso-idx-pagination__prev" href="<?php echo esc_url( $page_url( $criteria->page - 1 ) ); ?>" data-page="<?php echo esc_attr( (string) ( $criteria->page - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'reso-idx' ); ?></a>
		<?php endif; ?>
		<span class="reso-idx-pagination__status">
			<?php
			/* translators: 1: current page, 2: total pages */
			echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'reso-idx' ), $criteria->page, $results['pages'] ) );
			?>
		</span>
		<?php if ( $criteria->page < $results['pages'] ) : ?>
			<a class="reso-idx-pagination__next" href="<?php echo esc_url( $page_url( $criteria->page + 1 ) ); ?>" data-page="<?php echo esc_attr( (string) ( $criteria->page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'reso-idx' ); ?></a>
		<?php endif; ?>
	</nav>
<?php endif; ?>
