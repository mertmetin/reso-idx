<?php
/**
 * Scripted HTTP transport.
 *
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Support;

use ResoIdx\Api\Transport;

/**
 * Returns queued responses and records every requested URL.
 */
final class FakeTransport implements Transport {

	/**
	 * Requested URLs, decoded for readable assertions.
	 *
	 * @var list<string>
	 */
	public array $requests = array();

	/**
	 * Queued responses, or a handler deciding by URL.
	 *
	 * @param list<array{status: int, headers?: array<string, string>, body: string}|\Throwable> $queue   Responses in order.
	 * @param (callable(string): array{status: int, body: string})|null                          $handler Optional URL-based handler.
	 */
	public function __construct( private array $queue = array(), private $handler = null ) {}

	/**
	 * JSON response helper.
	 *
	 * @param mixed  $data   Body data.
	 * @param int    $status HTTP status.
	 * @param array<string, string> $headers Headers.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function json( $data, int $status = 200, array $headers = array() ): array {
		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => (string) json_encode( $data ),
		);
	}

	public function get( string $url, array $headers ): array {
		$this->requests[] = rawurldecode( $url );
		if ( null !== $this->handler ) {
			$r = ( $this->handler )( rawurldecode( $url ) );
			return $r + array( 'headers' => array() );
		}
		$next = array_shift( $this->queue );
		if ( null === $next ) {
			throw new \LogicException( 'No response queued for ' . $url );
		}
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		return $next + array( 'headers' => array() );
	}
}
