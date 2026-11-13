<?php
/**
 * HTTP transport contract.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Api;

/**
 * Sends a GET request. Kept tiny so tests can swap in a fake.
 */
interface Transport {

	/**
	 * Send a GET request.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 * @throws ApiException On network failure.
	 */
	public function get( string $url, array $headers ): array;
}
