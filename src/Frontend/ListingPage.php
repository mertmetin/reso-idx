<?php
/**
 * Listing detail pages at /listing/{key}/{address-slug}/.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Frontend;

use ResoIdx\Settings;
use ResoIdx\Storage\ListingRepository;

/**
 * Virtual pages for listings: a rewrite rule, a template inside the active
 * theme (classic or block theme), page title, canonical URL, Open Graph tags
 * and schema.org JSON-LD.
 */
final class ListingPage {

	public const QUERY_VAR = 'reso_idx_listing';

	/**
	 * The listing for the current request, once resolved.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $listing = null;

	/**
	 * Hook everything up.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'resolve' ) );
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_filter( 'pre_get_document_title', array( self::class, 'title' ) );
		add_action( 'wp_head', array( self::class, 'head' ), 1 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
	}

	/**
	 * The rewrite rule.
	 */
	public static function add_rewrite_rule(): void {
		$base = preg_quote( trim( (string) Settings::get( 'listing_base' ), '/' ), '#' );
		add_rewrite_rule( '^' . $base . '/([A-Za-z0-9_.-]+)(?:/[^/]*)?/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param list<string> $vars Query vars.
	 * @return list<string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Load the listing; 404 if it's gone, and redirect to the canonical slug.
	 */
	public static function resolve(): void {
		$key = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $key ) {
			return;
		}
		$listing = ( new ListingRepository() )->find( $key );
		if ( null === $listing ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		self::$listing = $listing;

		$canonical = View::url( $listing );
		$requested = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( '' !== $requested && untrailingslashit( $requested ) !== untrailingslashit( (string) wp_parse_url( $canonical, PHP_URL_PATH ) ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}

		global $wp_query;
		$wp_query->is_404  = false;
		$wp_query->is_home = false;
		status_header( 200 );

		wp_enqueue_style( 'reso-idx' );
		wp_enqueue_script( 'reso-idx-listing' );
		if ( Settings::get( 'map_enabled' ) && null !== $listing['latitude'] ) {
			wp_enqueue_style( 'leaflet' );
			wp_enqueue_script( 'leaflet' );
		}
	}

	/**
	 * Use the plugin's page wrapper.
	 *
	 * @param string $template Template WordPress picked.
	 */
	public static function template( string $template ): string {
		if ( null === self::$listing ) {
			return $template;
		}
		$override = locate_template( 'reso-idx/listing-page.php' );
		return '' !== $override ? $override : RESO_IDX_DIR . 'templates/listing-page.php';
	}

	/**
	 * The listing being viewed.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		return self::$listing;
	}

	/**
	 * Page title.
	 *
	 * @param string $title Title so far.
	 */
	public static function title( string $title ): string {
		if ( null === self::$listing ) {
			return $title;
		}
		$l = self::$listing;
		return sprintf( '%s, %s – %s | %s', View::street( $l ), $l['city'], View::price( $l['price'] ), get_bloginfo( 'name' ) );
	}

	/**
	 * Body class.
	 *
	 * @param list<string> $classes Classes.
	 * @return list<string>
	 */
	public static function body_class( array $classes ): array {
		if ( null !== self::$listing ) {
			$classes[] = 'reso-idx-listing-page';
		}
		return $classes;
	}

	/**
	 * Canonical, description, Open Graph and JSON-LD.
	 */
	public static function head(): void {
		if ( null === self::$listing ) {
			return;
		}
		$l           = self::$listing;
		$url         = View::url( $l );
		$description = wp_trim_words( $l['remarks'], 30, '…' );

		remove_action( 'wp_head', 'rel_canonical' );
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:type" content="website">' . "\n" );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( View::street( $l ) . ' – ' . View::price( $l['price'] ) ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		if ( ! empty( $l['photos'][0] ) ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $l['photos'][0] ) );
		}
		echo '<script type="application/ld+json">' . wp_json_encode( self::json_ld( $l ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and > escaped.
	}

	/**
	 * Schema.org RealEstateListing data.
	 *
	 * @param array<string, mixed> $l Listing row.
	 * @return array<string, mixed>
	 */
	public static function json_ld( array $l ): array {
		$place = array(
			'@type'                  => 'SingleFamilyResidence',
			'numberOfRooms'          => $l['beds'],
			'numberOfBathroomsTotal' => $l['baths'],
			'address'                => array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $l['address_hidden'] ? null : View::street( $l ),
					'addressLocality' => $l['city'],
					'addressRegion'   => $l['province'],
					'postalCode'      => $l['address_hidden'] ? null : $l['postal_code'],
				)
			),
		);
		if ( null !== $l['latitude'] ) {
			$place['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $l['latitude'],
				'longitude' => $l['longitude'],
			);
		}
		return array_filter(
			array(
				'@context'    => 'https://schema.org',
				'@type'       => 'RealEstateListing',
				'name'        => View::street( $l ) . ', ' . $l['city'],
				'url'         => View::url( $l ),
				'description' => $l['remarks'],
				'datePosted'  => $l['listed_at'],
				'image'       => array_slice( $l['photos'], 0, 5 ),
				'about'       => array_filter( $place, static fn ( $v ) => null !== $v ),
				'offers'      => null === $l['price'] ? null : array(
					'@type'         => 'Offer',
					'price'         => $l['price'],
					'priceCurrency' => (string) apply_filters( 'reso_idx_currency', 'CAD' ),
					'availability'  => 'https://schema.org/InStock',
				),
			),
			static fn ( $v ) => null !== $v && array() !== $v
		);
	}
}
