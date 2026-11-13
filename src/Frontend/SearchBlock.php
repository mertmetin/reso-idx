<?php
/**
 * Listing search block and shortcode.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Frontend;

use ResoIdx\Settings;
use ResoIdx\Storage\ListingRepository;
use ResoIdx\Storage\SearchCriteria;

/**
 * The `reso-idx/listing-search` block and the `[reso_idx_search]` shortcode.
 *
 * Both render on the server, so results are in the page HTML for visitors
 * and search engines; search.js then takes over for filtering in place.
 */
final class SearchBlock {

	/**
	 * Front-end query parameter → SearchCriteria key. WordPress reserves
	 * `type` and `page` as query vars, so the form uses other names.
	 */
	private const PARAMS = array(
		'q'         => 'q',
		'city'      => 'city',
		'ptype'     => 'type',
		'min_price' => 'min_price',
		'max_price' => 'max_price',
		'beds'      => 'beds',
		'baths'     => 'baths',
		'sort'      => 'sort',
		'pg'        => 'page',
	);

	/**
	 * Register the block and shortcode.
	 */
	public static function register(): void {
		add_action(
			'init',
			static function (): void {
				register_block_type(
					RESO_IDX_DIR . 'blocks/listing-search',
					array( 'render_callback' => array( self::class, 'render_block' ) )
				);
				add_shortcode( 'reso_idx_search', array( self::class, 'shortcode' ) );
			}
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		return self::render(
			array(
				'per_page' => (int) ( $attributes['perPage'] ?? 0 ),
				'show_map' => (bool) ( $attributes['showMap'] ?? true ),
				'city'     => (string) ( $attributes['city'] ?? '' ),
				'type'     => (string) ( $attributes['propertyType'] ?? '' ),
			),
			get_block_wrapper_attributes( array( 'class' => 'reso-idx-search' ) )
		);
	}

	/**
	 * `[reso_idx_search per_page="12" map="yes" city="" type=""]`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'per_page' => '',
				'map'      => 'yes',
				'city'     => '',
				'type'     => '',
			),
			is_array( $atts ) ? $atts : array(),
			'reso_idx_search'
		);
		return self::render(
			array(
				'per_page' => (int) $atts['per_page'],
				'show_map' => ! in_array( strtolower( $atts['map'] ), array( 'no', 'false', '0' ), true ),
				'city'     => sanitize_text_field( $atts['city'] ),
				'type'     => sanitize_text_field( $atts['type'] ),
			),
			'class="reso-idx-search"'
		);
	}

	/**
	 * Render the search UI.
	 *
	 * @param array{per_page: int, show_map: bool, city: string, type: string} $options Display options.
	 * @param string                                                           $wrapper Wrapper attributes.
	 */
	public static function render( array $options, string $wrapper ): string {
		$fixed = array_filter(
			array(
				'city'  => $options['city'],
				'ptype' => $options['type'],
			)
		);

		// Read-only search filters from the URL; no state changes, so no nonce.
		$input    = array_merge( wp_unslash( $_GET ), $fixed ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by SearchCriteria.
		$per_page = $options['per_page'] > 0 ? $options['per_page'] : (int) Settings::get( 'per_page' );
		$criteria = SearchCriteria::from_array( self::to_criteria_input( $input ), $per_page );
		$repo     = new ListingRepository();
		$show_map = $options['show_map'] && Settings::get( 'map_enabled' ) && $repo->has_coordinates();

		if ( $show_map ) {
			wp_enqueue_style( 'leaflet' );
			wp_enqueue_script( 'leaflet' );
		}
		wp_enqueue_style( 'reso-idx' );
		wp_enqueue_script( 'reso-idx-search' );

		return View::render(
			'search',
			array(
				'criteria'   => $criteria,
				'fixed'      => $fixed,
				'types'      => $repo->distinct( 'property_type' ),
				'show_map'   => $show_map,
				'results'    => self::results_html( $criteria, self::current_url() ),
				'pins'       => $show_map ? $repo->pins( $criteria ) : array(),
				'disclaimer' => self::disclaimer_html(),
				'wrapper'    => $wrapper,
				'config'     => array(
					// Root-relative, so requests match the origin the visitor is on.
					'endpoint' => wp_make_link_relative( rest_url( 'reso-idx/v1/listings' ) ),
					'listings' => wp_make_link_relative( trailingslashit( home_url( trim( (string) Settings::get( 'listing_base' ), '/' ) ) ) ),
					'perPage'  => $per_page,
					'fixed'    => (object) $fixed,
					'map'      => $show_map,
				),
			)
		);
	}

	/**
	 * Results HTML for a criteria and the page it appears on.
	 *
	 * @param SearchCriteria $criteria Filters.
	 * @param string         $base_url Page URL for pagination links.
	 */
	public static function results_html( SearchCriteria $criteria, string $base_url ): string {
		$params   = self::to_front_params( $criteria->to_query_args() );
		$page_url = static fn ( int $page ): string => add_query_arg(
			array_merge( $params, $page > 1 ? array( 'pg' => $page ) : array() ),
			remove_query_arg( array_keys( self::PARAMS ), $base_url )
		);
		return View::render(
			'results',
			array(
				'results'  => ( new ListingRepository() )->search( $criteria ),
				'criteria' => $criteria,
				'page_url' => $page_url,
			)
		);
	}

	/**
	 * Disclaimer HTML.
	 */
	public static function disclaimer_html(): string {
		return View::render(
			'disclaimer',
			array(
				'disclaimer'  => (string) Settings::get( 'disclaimer' ),
				'last_synced' => ( new ListingRepository() )->last_synced(),
			)
		);
	}

	/**
	 * Front-end parameter names → SearchCriteria input.
	 *
	 * @param array<string, mixed> $input Front-end parameters.
	 * @return array<string, mixed>
	 */
	public static function to_criteria_input( array $input ): array {
		$out = array();
		foreach ( self::PARAMS as $front => $key ) {
			if ( isset( $input[ $front ] ) ) {
				$out[ $key ] = $input[ $front ];
			}
		}
		return $out;
	}

	/**
	 * SearchCriteria keys → front-end parameter names.
	 *
	 * @param array<string, mixed> $args Criteria args.
	 * @return array<string, mixed>
	 */
	private static function to_front_params( array $args ): array {
		$map = array_flip( self::PARAMS );
		$out = array();
		foreach ( $args as $key => $value ) {
			$out[ $map[ $key ] ?? $key ] = $value;
		}
		return $out;
	}

	/**
	 * URL of the current request.
	 */
	private static function current_url(): string {
		$permalink = is_singular() ? get_permalink() : false;
		return is_string( $permalink ) ? $permalink : home_url( '/' );
	}
}
