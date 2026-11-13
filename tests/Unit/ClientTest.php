<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Api\ApiException;
use ResoIdx\Api\Client;
use ResoIdx\Api\ODataQuery;
use ResoIdx\Tests\Support\FakeTransport;

final class ClientTest extends TestCase {

	/** @var list<float> */
	private array $sleeps = array();

	private function client( FakeTransport $transport, int $retries = 3 ): Client {
		return new Client( 'https://api.test/odata', 'secret', $transport, $retries, function ( float $s ): void {
			$this->sleeps[] = $s;
		} );
	}

	public function test_builds_url_from_endpoint_and_query(): void {
		$client = $this->client( new FakeTransport() );
		$this->assertSame( 'https://api.test/odata/Property?%24top=1', $client->url( 'Property', ODataQuery::create()->top( 1 ) ) );
	}

	public function test_follows_next_links_including_relative_ones(): void {
		$transport = new FakeTransport(
			array(
				FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'A' ) ), '@odata.nextLink' => 'https://api.test/odata/Property?page=2' ) ),
				FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'B' ) ), '@odata.nextLink' => 'Property?page=3' ) ),
				FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'C' ) ) ) ),
			)
		);
		$rows = $this->client( $transport )->all( 'Property', ODataQuery::create()->top( 2 ) );

		$this->assertSame( array( 'A', 'B', 'C' ), array_column( $rows, 'ListingKey' ) );
		$this->assertSame( 'https://api.test/odata/Property?page=3', $transport->requests[2] );
	}

	public function test_pages_with_skip_when_server_sends_no_next_link(): void {
		$transport = new FakeTransport(
			array(
				FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'A' ), array( 'ListingKey' => 'B' ) ) ) ),
				FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'C' ) ) ) ),
			)
		);
		$rows = $this->client( $transport )->all( 'Property', ODataQuery::create()->top( 2 ) );

		$this->assertCount( 3, $rows );
		$this->assertStringContainsString( '$skip=2', $transport->requests[1] );
	}

	public function test_retries_rate_limits_using_retry_after(): void {
		$transport = new FakeTransport(
			array(
				FakeTransport::json( array(), 429, array( 'retry-after' => '7' ) ),
				FakeTransport::json( array(), 503 ),
				FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'A' ) ) ) ),
			)
		);
		$page = $this->client( $transport )->page( 'Property', ODataQuery::create() );

		$this->assertSame( 'A', $page['value'][0]['ListingKey'] );
		$this->assertSame( array( 7.0, 2.0 ), $this->sleeps );
	}

	public function test_retries_network_errors_then_gives_up(): void {
		$transport = new FakeTransport(
			array(
				new ApiException( 'timeout', 0 ),
				new ApiException( 'timeout', 0 ),
			)
		);
		$this->expectException( ApiException::class );
		try {
			$this->client( $transport, 1 )->page( 'Property', ODataQuery::create() );
		} finally {
			$this->assertSame( array( 1.0 ), $this->sleeps );
		}
	}

	public function test_auth_errors_fail_fast_with_a_helpful_message(): void {
		$transport = new FakeTransport( array( array( 'status' => 401, 'body' => '' ) ) );
		try {
			$this->client( $transport )->page( 'Property', ODataQuery::create() );
			$this->fail( 'Expected an exception.' );
		} catch ( ApiException $e ) {
			$this->assertSame( 401, $e->getCode() );
			$this->assertStringContainsString( 'check the access token', $e->getMessage() );
			$this->assertFalse( $e->is_transient() );
			$this->assertSame( array(), $this->sleeps );
		}
	}

	public function test_passes_on_odata_error_messages(): void {
		$transport = new FakeTransport( array( FakeTransport::json( array( 'error' => array( 'message' => "Could not find a property named 'LivingArea'" ) ), 400 ) ) );
		$this->expectExceptionMessage( 'HTTP 400: Could not find a property named' );
		$this->client( $transport )->page( 'Property', ODataQuery::create() );
	}

	public function test_rejects_non_odata_bodies(): void {
		$transport = new FakeTransport( array( array( 'status' => 200, 'body' => '<html>Cloudflare</html>' ) ) );
		$this->expectException( ApiException::class );
		$this->client( $transport )->page( 'Property', ODataQuery::create() );
	}
}
