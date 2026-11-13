<?php
/**
 * Formatting and template helpers.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Frontend;

use ResoIdx\Settings;

/**
 * Shared helpers for templates, the block, the REST API and the listing page.
 */
final class View {

	/**
	 * Render a template from /templates with variables.
	 *
	 * A theme can override any template by copying it to `reso-idx/{name}.php`
	 * in the theme folder.
	 *
	 * @param string               $name Template name without extension.
	 * @param array<string, mixed> $vars Variables available in the template.
	 */
	public static function render( string $name, array $vars = array() ): string {
		$file = locate_template( 'reso-idx/' . $name . '.php' );
		if ( '' === $file ) {
			$file = RESO_IDX_DIR . 'templates/' . $name . '.php';
		}
		ob_start();
		( static function ( string $__file, array $__vars ): void {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $__file;
		} )( $file, $vars );
		return (string) ob_get_clean();
	}

	/**
	 * Price as currency, e.g. $649,900.
	 *
	 * @param int|null $price Price.
	 */
	public static function price( ?int $price ): string {
		if ( null === $price ) {
			return __( 'Price on request', 'reso-idx' );
		}
		$symbol = (string) apply_filters( 'reso_idx_currency_symbol', '$' );
		return $symbol . number_format_i18n( $price );
	}

	/**
	 * Short price for map pins, e.g. $650K or $1.2M.
	 *
	 * @param int|null $price Price.
	 */
	public static function short_price( ?int $price ): string {
		if ( null === $price ) {
			return '';
		}
		if ( $price >= 1_000_000 ) {
			return '$' . rtrim( rtrim( number_format( $price / 1_000_000, 2 ), '0' ), '.' ) . 'M';
		}
		return '$' . round( $price / 1000 ) . 'K';
	}

	/**
	 * Street line with unit, or a withheld notice.
	 *
	 * @param array<string, mixed> $listing Listing row.
	 */
	public static function street( array $listing ): string {
		if ( $listing['address_hidden'] || '' === $listing['address'] ) {
			return __( 'Address available on request', 'reso-idx' );
		}
		return '' !== $listing['unit'] ? $listing['unit'] . ' – ' . $listing['address'] : $listing['address'];
	}

	/**
	 * "Neighbourhood, City, Province".
	 *
	 * @param array<string, mixed> $listing Listing row.
	 */
	public static function locality( array $listing ): string {
		$parts = array_filter( array( $listing['region'], $listing['city'], $listing['province'] ) );
		return implode( ', ', array_unique( $parts ) );
	}

	/**
	 * Public URL of a listing page.
	 *
	 * @param array<string, mixed> $listing Listing row.
	 */
	public static function url( array $listing ): string {
		$slug = sanitize_title( $listing['address_hidden'] ? $listing['city'] : $listing['address'] . ' ' . $listing['city'] );
		$base = trim( (string) Settings::get( 'listing_base' ), '/' );
		return home_url( user_trailingslashit( $base . '/' . rawurlencode( (string) $listing['listing_key'] ) . ( '' !== $slug ? '/' . $slug : '' ) ) );
	}

	/**
	 * First photo or the placeholder.
	 *
	 * @param array<string, mixed> $listing Listing row.
	 */
	public static function cover( array $listing ): string {
		return $listing['photos'][0] ?? RESO_IDX_URL . 'assets/placeholder.svg';
	}

	/**
	 * "3 bd · 2 ba · 1,450 sq ft".
	 *
	 * @param array<string, mixed> $listing Listing row.
	 */
	public static function facts( array $listing ): string {
		$facts = array();
		if ( null !== $listing['beds'] ) {
			/* translators: %d: number of bedrooms */
			$facts[] = sprintf( _n( '%d bed', '%d beds', $listing['beds'], 'reso-idx' ), $listing['beds'] );
		}
		if ( null !== $listing['baths'] ) {
			/* translators: %d: number of bathrooms */
			$facts[] = sprintf( _n( '%d bath', '%d baths', $listing['baths'], 'reso-idx' ), $listing['baths'] );
		}
		if ( '' !== $listing['living_area'] ) {
			$area    = is_numeric( $listing['living_area'] ) ? number_format_i18n( (int) $listing['living_area'] ) : $listing['living_area'];
			$facts[] = $area . ' ' . __( 'sq ft', 'reso-idx' );
		}
		return implode( ' · ', $facts );
	}

	/**
	 * Fields safe to expose in the REST API.
	 *
	 * @param array<string, mixed> $listing Listing row.
	 * @return array<string, mixed>
	 */
	public static function to_api( array $listing ): array {
		return array(
			'key'          => $listing['listing_key'],
			'mls_number'   => $listing['listing_id'],
			'status'       => $listing['status'],
			'type'         => $listing['property_type'],
			'subtype'      => $listing['property_subtype'],
			'price'        => $listing['price'],
			'beds'         => $listing['beds'],
			'baths'        => $listing['baths'],
			'living_area'  => $listing['living_area'],
			'year_built'   => $listing['year_built'],
			'address'      => $listing['address_hidden'] ? null : self::street( $listing ),
			'city'         => $listing['city'],
			'neighborhood' => $listing['region'],
			'province'     => $listing['province'],
			'postal_code'  => $listing['address_hidden'] ? null : $listing['postal_code'],
			'latitude'     => $listing['latitude'],
			'longitude'    => $listing['longitude'],
			'remarks'      => $listing['remarks'],
			'office'       => $listing['office_name'],
			'agent'        => $listing['agent_name'],
			'photos'       => $listing['photos'],
			'virtual_tour' => $listing['virtual_tour'] ? $listing['virtual_tour'] : null,
			'details'      => $listing['details'],
			'listed_at'    => $listing['listed_at'],
			'updated_at'   => mysql_to_rfc3339( $listing['modified_at'] ),
			'url'          => self::url( $listing ),
		);
	}
}
