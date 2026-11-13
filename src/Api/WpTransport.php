<?php
/**
 * Transport backed by the WordPress HTTP API.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Api;

/**
 * Uses wp_remote_get(), so proxies and HTTP filters configured in WordPress apply.
 */
final class WpTransport implements Transport {

	/**
	 * Send a GET request.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws ApiException On network failure.
	 */
	public function get( string $url, array $headers ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'headers'    => $headers,
				'user-agent' => (string) apply_filters( 'reso_idx_user_agent', 'reso-idx/' . RESO_IDX_VERSION . ' (WordPress)' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new ApiException( esc_html( $response->get_error_message() ), 0 );
		}
		$out_headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out_headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $out_headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
