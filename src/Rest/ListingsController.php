<?php
/**
 * Public REST API.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Rest;

use ResoIdx\Frontend\SearchBlock;
use ResoIdx\Frontend\View;
use ResoIdx\Settings;
use ResoIdx\Storage\ListingRepository;
use ResoIdx\Storage\SearchCriteria;

/**
 * `GET /reso-idx/v1/listings` and `GET /reso-idx/v1/listings/{key}`.
 *
 * Read-only and public: it serves the same IDX data as the public pages.
 */
final class ListingsController {

	public const NAMESPACE = 'reso-idx/v1';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/listings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => '__return_true',
				'args'                => $this->collection_args(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/listings/(?P<key>[A-Za-z0-9_.-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Search listings.
	 *
	 * With `render=1` the response also has the results HTML and map pins, which
	 * the search block uses so cards are rendered by one PHP template.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$criteria = SearchCriteria::from_array( $request->get_params(), (int) Settings::get( 'per_page' ) );
		$repo     = new ListingRepository();
		$results  = $repo->search( $criteria );

		$data = array(
			'items' => array_map( array( View::class, 'to_api' ), $results['items'] ),
			'total' => $results['total'],
			'pages' => $results['pages'],
			'page'  => $criteria->page,
		);

		if ( $request->get_param( 'render' ) ) {
			$base         = (string) wp_validate_redirect( esc_url_raw( (string) $request->get_param( 'base' ) ), home_url( '/' ) );
			$data['html'] = SearchBlock::results_html( $criteria, $base );
			$data['pins'] = $repo->pins( $criteria );
		}

		$response = new \WP_REST_Response( $data );
		$response->header( 'X-WP-Total', (string) $results['total'] );
		$response->header( 'X-WP-TotalPages', (string) $results['pages'] );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * One listing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$listing = ( new ListingRepository() )->find( (string) $request['key'] );
		if ( null === $listing ) {
			return new \WP_Error( 'reso_idx_not_found', __( 'Listing not found.', 'reso-idx' ), array( 'status' => 404 ) );
		}
		$response = new \WP_REST_Response( View::to_api( $listing ) );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Documented query parameters (validation happens in SearchCriteria).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function collection_args(): array {
		$string = array( 'type' => 'string' );
		$int    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		return array(
			'q'         => $string + array( 'description' => __( 'Address, neighbourhood, city, postal code or MLS® number.', 'reso-idx' ) ),
			'city'      => $string,
			'type'      => $string + array( 'description' => __( 'Property type or subtype.', 'reso-idx' ) ),
			'min_price' => $int,
			'max_price' => $int,
			'beds'      => $int + array( 'description' => __( 'Minimum bedrooms.', 'reso-idx' ) ),
			'baths'     => $int + array( 'description' => __( 'Minimum bathrooms.', 'reso-idx' ) ),
			'bounds'    => $string + array( 'description' => __( 'Map bounds: south,west,north,east.', 'reso-idx' ) ),
			'sort'      => array(
				'type' => 'string',
				'enum' => array_keys( SearchCriteria::SORTS ),
			),
			'page'      => $int,
			'per_page'  => $int + array( 'maximum' => 48 ),
			'render'    => array( 'type' => 'boolean' ),
			'base'      => array(
				'type'   => 'string',
				'format' => 'uri',
			),
		);
	}
}
