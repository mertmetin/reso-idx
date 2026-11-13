<?php
/**
 * Scripts and styles.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Frontend;

/**
 * Registers assets; the block and listing page enqueue what they need.
 */
final class Assets {

	private const LEAFLET = '1.9.4';

	/**
	 * Hook registration in before blocks are registered.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ), 5 );
		add_filter( 'style_loader_tag', array( self::class, 'leaflet_sri' ), 10, 2 );
		add_filter( 'script_loader_tag', array( self::class, 'leaflet_sri' ), 10, 2 );
	}

	/**
	 * Register handles.
	 */
	public static function register_assets(): void {
		$leaflet_url = (string) apply_filters( 'reso_idx_leaflet_url', 'https://unpkg.com/leaflet@' . self::LEAFLET . '/dist/' );

		wp_register_style( 'leaflet', $leaflet_url . 'leaflet.css', array(), self::LEAFLET );
		wp_register_script( 'leaflet', $leaflet_url . 'leaflet.js', array(), self::LEAFLET, array( 'in_footer' => true ) );

		wp_register_style( 'reso-idx', RESO_IDX_URL . 'assets/css/reso-idx.css', array(), RESO_IDX_VERSION );
		wp_register_script(
			'reso-idx-search',
			RESO_IDX_URL . 'assets/js/search.js',
			array(),
			RESO_IDX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_script(
			'reso-idx-listing',
			RESO_IDX_URL . 'assets/js/listing.js',
			array(),
			RESO_IDX_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( 'reso-idx-search', 'reso-idx' );
	}

	/**
	 * Subresource integrity for the CDN copy of Leaflet.
	 *
	 * @param string $tag    HTML tag.
	 * @param string $handle Handle.
	 */
	public static function leaflet_sri( string $tag, string $handle ): string {
		if ( 'leaflet' !== $handle || ! str_contains( $tag, 'unpkg.com/leaflet@' . self::LEAFLET ) ) {
			return $tag;
		}
		$hash = str_contains( $tag, '<script' )
			? 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo='
			: 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
		return str_replace( ' src=', ' integrity="' . $hash . '" crossorigin="" src=', str_replace( ' href=', ' integrity="' . $hash . '" crossorigin="" href=', $tag ) );
	}
}
