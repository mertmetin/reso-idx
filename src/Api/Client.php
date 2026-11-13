<?php
/**
 * RESO Web API client.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Api;

/**
 * Talks to an OData endpoint: bearer auth, paging and retry with backoff.
 */
final class Client {

	private const RETRY_STATUSES = array( 429, 500, 502, 503, 504 );

	/**
	 * Base URL, always ending in a slash.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * How to wait between retries. Swapped out in tests.
	 *
	 * @var callable(float): void
	 */
	private $sleeper;

	/**
	 * Set up the client.
	 *
	 * @param string                       $endpoint    Base URL, e.g. https://api.example.com/odata/.
	 * @param string                       $token       Bearer token.
	 * @param Transport                    $transport   HTTP transport.
	 * @param int                          $max_retries Retries for 429 and 5xx responses.
	 * @param (callable(float): void)|null $sleeper   Called with seconds to wait.
	 */
	public function __construct(
		string $endpoint,
		private string $token,
		private Transport $transport,
		private int $max_retries = 3,
		?callable $sleeper = null
	) {
		$this->endpoint = rtrim( $endpoint, '/' ) . '/';
		$this->sleeper  = $sleeper ?? static function ( float $seconds ): void {
			usleep( (int) ( $seconds * 1_000_000 ) );
		};
	}

	/**
	 * Fetch one page.
	 *
	 * @param string     $resource_name Resource name, e.g. Property.
	 * @param ODataQuery $query    Query.
	 * @return array{value: list<array<string, mixed>>, next: string|null}
	 * @throws ApiException On HTTP or decoding errors.
	 */
	public function page( string $resource_name, ODataQuery $query ): array {
		return $this->fetch_url( $this->url( $resource_name, $query ) );
	}

	/**
	 * Fetch every page, following `@odata.nextLink`, or `$skip` when the server
	 * doesn't send one.
	 *
	 * @param string     $resource_name  Resource name.
	 * @param ODataQuery $query     Query; its `$top` is the page size.
	 * @param int        $max_pages Safety stop.
	 * @return list<array<string, mixed>>
	 * @throws ApiException On HTTP or decoding errors.
	 */
	public function all( string $resource_name, ODataQuery $query, int $max_pages = 200 ): array {
		$size  = $query->get_top() ?? 200;
		$query = $query->top( $size );
		$rows  = array();
		$skip  = 0;
		$url   = $this->url( $resource_name, $query );

		for ( $i = 0; $i < $max_pages; $i++ ) {
			$page = $this->fetch_url( $url );
			array_push( $rows, ...$page['value'] );

			if ( null !== $page['next'] ) {
				$url = $page['next'];
			} elseif ( count( $page['value'] ) >= $size ) {
				$skip += $size;
				$url   = $this->url( $resource_name, $query->skip( $skip ) );
			} else {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Full request URL for a resource and query.
	 *
	 * @param string     $resource_name Resource name.
	 * @param ODataQuery $query    Query.
	 */
	public function url( string $resource_name, ODataQuery $query ): string {
		$qs = $query->to_query_string();
		return $this->endpoint . rawurlencode( $resource_name ) . ( '' !== $qs ? '?' . $qs : '' );
	}

	/**
	 * GET a URL with retries, and decode the OData envelope.
	 *
	 * @param string $url Absolute URL.
	 * @return array{value: list<array<string, mixed>>, next: string|null}
	 * @throws ApiException On HTTP or decoding errors.
	 */
	private function fetch_url( string $url ): array {
		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->token,
		);

		$attempt = 0;
		while ( true ) {
			try {
				$response = $this->transport->get( $url, $headers );
			} catch ( ApiException $e ) {
				if ( $attempt >= $this->max_retries ) {
					throw $e;
				}
				( $this->sleeper )( $this->backoff( $attempt, null ) );
				++$attempt;
				continue;
			}

			$status = $response['status'];
			if ( in_array( $status, self::RETRY_STATUSES, true ) && $attempt < $this->max_retries ) {
				( $this->sleeper )( $this->backoff( $attempt, $response['headers']['retry-after'] ?? null ) );
				++$attempt;
				continue;
			}
			if ( $status < 200 || $status >= 300 ) {
				throw new ApiException( esc_html( self::error_message( $status, $response['body'] ) ), (int) $status );
			}
			break;
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['value'] ) || ! is_array( $data['value'] ) ) {
			throw new ApiException( 'The server did not return an OData collection.', 200 );
		}

		$next = isset( $data['@odata.nextLink'] ) && is_string( $data['@odata.nextLink'] ) ? $data['@odata.nextLink'] : null;
		if ( null !== $next && ! str_starts_with( $next, 'http' ) ) {
			$next = $this->endpoint . ltrim( $next, '/' );
		}

		return array(
			'value' => array_values( array_filter( $data['value'], 'is_array' ) ),
			'next'  => $next,
		);
	}

	/**
	 * Seconds to wait before the next try: Retry-After if the server sent it,
	 * otherwise 1s, 2s, 4s... with a cap.
	 *
	 * @param int         $attempt     Zero-based attempt number.
	 * @param string|null $retry_after Retry-After header value.
	 */
	private function backoff( int $attempt, ?string $retry_after ): float {
		if ( null !== $retry_after && is_numeric( trim( $retry_after ) ) ) {
			return min( 60.0, max( 0.0, (float) $retry_after ) );
		}
		return min( 30.0, (float) ( 2 ** $attempt ) );
	}

	/**
	 * Readable error from an OData error body.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private static function error_message( int $status, string $body ): string {
		$data   = json_decode( $body, true );
		$detail = is_array( $data ) ? ( $data['error']['message'] ?? $data['message'] ?? '' ) : '';
		if ( ! is_string( $detail ) || '' === $detail ) {
			$detail = 401 === $status || 403 === $status ? 'check the access token' : 'unexpected response';
		}
		return sprintf( 'HTTP %d: %s', $status, mb_substr( $detail, 0, 300 ) );
	}
}
