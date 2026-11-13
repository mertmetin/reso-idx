<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Sync\Cursor;
use ResoIdx\Sync\Mapper;
use ResoIdx\Sync\Syncer;
use ResoIdx\Tests\Support\ArraySource;
use ResoIdx\Tests\Support\MemoryStore;

final class SyncerTest extends TestCase {

	private function record( string $key, string $ts, string $status = 'Active' ): array {
		return array(
			'ListingKey'            => $key,
			'StandardStatus'        => $status,
			'ListPrice'             => 500000,
			'City'                  => 'Ottawa',
			'ModificationTimestamp' => $ts,
		);
	}

	public function test_batches_through_records_that_share_a_timestamp(): void {
		// Five records at one instant, batch size two: keyset paging must not skip or repeat.
		$records = array();
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $key ) {
			$records[] = $this->record( $key, '2026-11-01T12:00:00Z' );
		}
		$records[] = $this->record( 'F', '2026-11-01T13:00:00Z' );
		$store     = new MemoryStore();
		$syncer    = new Syncer( new ArraySource( $records ), $store, new Mapper() );

		$result = $syncer->run( Cursor::start(), 2, 2 );
		$this->assertFalse( $result->done );
		$this->assertSame( 'D', $result->cursor->key );

		$result = $syncer->run( $result->cursor, 2, 5 );
		$this->assertTrue( $result->done );
		$this->assertSame( array( 'A', 'B', 'C', 'D', 'E', 'F' ), $store->keys() );
		$this->assertSame( array( 1 ), array_values( array_unique( $store->writes ) ), 'Each record written exactly once.' );
	}

	public function test_a_short_batch_does_not_end_the_sync(): void {
		// RemoteSource can return fewer records than asked after dropping ties the
		// cursor already covers. The sync must keep reading until a batch is empty.
		$records = array();
		foreach ( range( 1, 9 ) as $n ) {
			$records[] = $this->record( 'K' . $n, '2026-11-01T12:0' . $n . ':00Z' );
		}
		$source = new class( $records ) extends ArraySource {
			public function changes( Cursor $after, int $limit ): array {
				return array_slice( parent::changes( $after, $limit ), 0, $limit - 1 );
			}
		};
		$store  = new MemoryStore();
		$result = ( new Syncer( $source, $store, new Mapper() ) )->run( Cursor::start(), 4, 10 );

		$this->assertTrue( $result->done );
		$this->assertSame( 9, $store->count() );
	}

	public function test_removes_listings_that_leave_the_market(): void {
		$source = new ArraySource( array( $this->record( 'A', '2026-11-01T12:00:00Z' ), $this->record( 'B', '2026-11-01T12:00:00Z' ) ) );
		$store  = new MemoryStore();
		$syncer = new Syncer( $source, $store, new Mapper() );
		$cursor = $syncer->run( Cursor::start(), 10 )->cursor;

		$source->records[0] = $this->record( 'A', '2026-11-02T09:00:00Z', 'Closed' );
		$result             = $syncer->run( $cursor, 10 );

		$this->assertSame( 1, $result->removed );
		$this->assertSame( array( 'B' ), $store->keys() );
	}

	public function test_an_empty_feed_is_done_and_keeps_the_cursor(): void {
		$cursor = new Cursor( '2026-11-01T12:00:00Z', 'Z' );
		$result = ( new Syncer( new ArraySource( array() ), new MemoryStore(), new Mapper() ) )->run( $cursor, 10 );
		$this->assertTrue( $result->done );
		$this->assertSame( $cursor, $result->cursor );
	}

	public function test_geocodes_listings_without_coordinates_once(): void {
		$record = $this->record( 'A', '2026-11-01T12:00:00Z' ) + array( 'StreetNumber' => '1', 'StreetName' => 'Main' );
		$source = new ArraySource( array( $record ) );
		$store  = new MemoryStore();
		$calls  = 0;
		$syncer = new Syncer(
			$source,
			$store,
			new Mapper(),
			static function ( array $row ) use ( &$calls ): array {
				++$calls;
				return array( 45.1, -75.2 );
			}
		);
		$cursor = $syncer->run( Cursor::start(), 10 )->cursor;
		$this->assertSame( 45.1, $store->rows['A']['latitude'] );

		$source->records[0]['ModificationTimestamp'] = '2026-11-02T12:00:00Z';
		$syncer->run( $cursor, 10 );
		$this->assertSame( 1, $calls, 'Stored coordinates are reused on later syncs.' );
		$this->assertSame( -75.2, $store->rows['A']['longitude'] );
	}

	public function test_never_geocodes_withheld_addresses(): void {
		$record = $this->record( 'A', '2026-11-01T12:00:00Z' ) + array( 'StreetNumber' => '1', 'StreetName' => 'Main', 'InternetAddressDisplayYN' => false );
		$store  = new MemoryStore();
		$syncer = new Syncer( new ArraySource( array( $record ) ), $store, new Mapper(), fn () => $this->fail( 'Geocoder must not be called.' ) );
		$syncer->run( Cursor::start(), 10 );
		$this->assertNull( $store->rows['A']['latitude'] );
	}

	public function test_reconcile_removes_listings_missing_from_the_feed(): void {
		$records = array();
		foreach ( range( 1, 10 ) as $n ) {
			$records[] = $this->record( 'K' . $n, '2026-11-01T12:00:00Z' );
		}
		$source = new ArraySource( $records );
		$store  = new MemoryStore();
		$syncer = new Syncer( $source, $store, new Mapper() );
		$syncer->run( Cursor::start(), 50 );

		$source->active_keys = array( 'K1', 'K2', 'K3', 'K4', 'K5', 'K6', 'K7', 'K8' );
		$this->assertSame( 2, $syncer->reconcile() );
		$this->assertNotContains( 'K9', $store->keys() );
	}

	public function test_reconcile_refuses_to_wipe_the_site_when_the_feed_looks_broken(): void {
		$records = array();
		foreach ( range( 1, 10 ) as $n ) {
			$records[] = $this->record( 'K' . $n, '2026-11-01T12:00:00Z' );
		}
		$source = new ArraySource( $records );
		$store  = new MemoryStore();
		$syncer = new Syncer( $source, $store, new Mapper() );
		$syncer->run( Cursor::start(), 50 );

		$source->active_keys = array( 'K1' );
		try {
			$syncer->reconcile();
			$this->fail( 'Expected reconcile to refuse.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Reconcile skipped', $e->getMessage() );
		}
		$this->assertSame( 10, $store->count() );
	}
}
