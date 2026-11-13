<?php
/**
 * Search block / shortcode: filters, map and results.
 *
 * Works without JavaScript (the form submits as a normal GET request); with
 * JavaScript, filters update results and the map in place through the REST API.
 * Override by copying to your-theme/reso-idx/search.php.
 *
 * @package ResoIdx
 *
 * @var \ResoIdx\Storage\SearchCriteria $criteria  Current filters.
 * @var array<string, string>           $fixed     Filters set by the block (hidden from visitors).
 * @var list<string>                    $types     Property types for the dropdown.
 * @var bool                            $show_map  Whether to show the map.
 * @var string                          $results   Rendered results HTML.
 * @var list<array<string, mixed>>      $pins      Map pins.
 * @var array<string, mixed>            $config    Settings passed to search.js.
 * @var string                          $disclaimer Rendered disclaimer HTML.
 * @var string                          $wrapper   Block wrapper attributes.
 */

defined( 'ABSPATH' ) || exit;

$price_steps = array( 200000, 300000, 400000, 500000, 600000, 700000, 800000, 1000000, 1250000, 1500000, 2000000 );
$uid         = wp_unique_id( 'reso-idx-' );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- from get_block_wrapper_attributes(). ?> data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
	<form class="reso-idx-filters" method="get" role="search" aria-label="<?php esc_attr_e( 'Search listings', 'reso-idx' ); ?>">
		<?php foreach ( $fixed as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endforeach; ?>

		<div class="reso-idx-filters__field reso-idx-filters__field--wide">
			<label for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'Location or MLS® number', 'reso-idx' ); ?></label>
			<input id="<?php echo esc_attr( $uid ); ?>-q" type="search" name="q" value="<?php echo esc_attr( $criteria->q ); ?>" placeholder="<?php esc_attr_e( 'Street, neighbourhood, city or postal code', 'reso-idx' ); ?>">
		</div>

		<?php if ( ! isset( $fixed['ptype'] ) && count( $types ) > 1 ) : ?>
			<div class="reso-idx-filters__field">
				<label for="<?php echo esc_attr( $uid ); ?>-type"><?php esc_html_e( 'Type', 'reso-idx' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-type" name="ptype">
					<option value=""><?php esc_html_e( 'Any', 'reso-idx' ); ?></option>
					<?php foreach ( $types as $type_option ) : ?>
						<option value="<?php echo esc_attr( $type_option ); ?>" <?php selected( $criteria->type, $type_option ); ?>><?php echo esc_html( $type_option ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<div class="reso-idx-filters__field">
			<label for="<?php echo esc_attr( $uid ); ?>-min"><?php esc_html_e( 'Min price', 'reso-idx' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-min" name="min_price">
				<option value=""><?php esc_html_e( 'No min', 'reso-idx' ); ?></option>
				<?php foreach ( $price_steps as $step ) : ?>
					<option value="<?php echo esc_attr( (string) $step ); ?>" <?php selected( $criteria->min_price, $step ); ?>><?php echo esc_html( \ResoIdx\Frontend\View::short_price( $step ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="reso-idx-filters__field">
			<label for="<?php echo esc_attr( $uid ); ?>-max"><?php esc_html_e( 'Max price', 'reso-idx' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-max" name="max_price">
				<option value=""><?php esc_html_e( 'No max', 'reso-idx' ); ?></option>
				<?php foreach ( $price_steps as $step ) : ?>
					<option value="<?php echo esc_attr( (string) $step ); ?>" <?php selected( $criteria->max_price, $step ); ?>><?php echo esc_html( \ResoIdx\Frontend\View::short_price( $step ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="reso-idx-filters__field">
			<label for="<?php echo esc_attr( $uid ); ?>-beds"><?php esc_html_e( 'Beds', 'reso-idx' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-beds" name="beds">
				<option value=""><?php esc_html_e( 'Any', 'reso-idx' ); ?></option>
				<?php for ( $n = 1; $n <= 5; $n++ ) : ?>
					<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $criteria->beds, $n ); ?>><?php echo esc_html( $n . '+' ); ?></option>
				<?php endfor; ?>
			</select>
		</div>

		<div class="reso-idx-filters__field">
			<label for="<?php echo esc_attr( $uid ); ?>-baths"><?php esc_html_e( 'Baths', 'reso-idx' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-baths" name="baths">
				<option value=""><?php esc_html_e( 'Any', 'reso-idx' ); ?></option>
				<?php for ( $n = 1; $n <= 4; $n++ ) : ?>
					<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $criteria->baths, $n ); ?>><?php echo esc_html( $n . '+' ); ?></option>
				<?php endfor; ?>
			</select>
		</div>

		<div class="reso-idx-filters__field">
			<label for="<?php echo esc_attr( $uid ); ?>-sort"><?php esc_html_e( 'Sort', 'reso-idx' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-sort" name="sort">
				<option value="newest" <?php selected( $criteria->sort, 'newest' ); ?>><?php esc_html_e( 'Newest', 'reso-idx' ); ?></option>
				<option value="price_asc" <?php selected( $criteria->sort, 'price_asc' ); ?>><?php esc_html_e( 'Price: low to high', 'reso-idx' ); ?></option>
				<option value="price_desc" <?php selected( $criteria->sort, 'price_desc' ); ?>><?php esc_html_e( 'Price: high to low', 'reso-idx' ); ?></option>
				<option value="beds_desc" <?php selected( $criteria->sort, 'beds_desc' ); ?>><?php esc_html_e( 'Most bedrooms', 'reso-idx' ); ?></option>
			</select>
		</div>

		<div class="reso-idx-filters__actions">
			<button type="submit" class="reso-idx-button"><?php esc_html_e( 'Search', 'reso-idx' ); ?></button>
		</div>
	</form>

	<div class="reso-idx-layout<?php echo $show_map ? ' reso-idx-layout--map' : ''; ?>">
		<?php if ( $show_map ) : ?>
			<div class="reso-idx-map-wrap">
				<div class="reso-idx-map" data-pins="<?php echo esc_attr( (string) wp_json_encode( $pins ) ); ?>" role="region" aria-label="<?php esc_attr_e( 'Map of listings', 'reso-idx' ); ?>"></div>
				<label class="reso-idx-map__toggle"><input type="checkbox" class="reso-idx-map__follow"> <?php esc_html_e( 'Search as I move the map', 'reso-idx' ); ?></label>
			</div>
		<?php endif; ?>
		<div class="reso-idx-results" aria-live="polite">
			<?php echo $results; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template. ?>
		</div>
	</div>

	<?php echo $disclaimer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template. ?>
</div>
