<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Sync\Cursor;

final class CursorTest extends TestCase {

	public function test_orders_by_timestamp_then_key(): void {
		$cursor = new Cursor( '2026-11-01T12:00:00Z', 'B' );
		$this->assertTrue( $cursor->is_before( '2026-11-01T12:00:01Z', 'A' ) );
		$this->assertTrue( $cursor->is_before( '2026-11-01T12:00:00Z', 'C' ) );
		$this->assertFalse( $cursor->is_before( '2026-11-01T12:00:00Z', 'B' ) );
		$this->assertFalse( $cursor->is_before( '2026-11-01T12:00:00Z', 'A' ) );
		$this->assertFalse( $cursor->is_before( '2026-11-01T11:59:59Z', 'Z' ) );
	}

	public function test_compares_fractional_seconds(): void {
		$cursor = new Cursor( '2026-11-01T12:00:00.500Z', 'M' );
		$this->assertTrue( $cursor->is_before( '2026-11-01T12:00:00.900Z', 'A' ), 'Later in the same second must not be skipped.' );
		$this->assertFalse( $cursor->is_before( '2026-11-01T12:00:00.100Z', 'Z' ) );
	}

	public function test_compares_across_time_zones(): void {
		$cursor = new Cursor( '2026-11-01T12:00:00Z', 'A' );
		$this->assertFalse( $cursor->is_before( '2026-11-01T07:00:00-05:00', 'A' ) );
	}

	public function test_round_trips_through_storage(): void {
		$cursor = Cursor::from_array( ( new Cursor( '2026-11-01T12:00:00Z', 'K1' ) )->to_array() );
		$this->assertSame( 'K1', $cursor->key );
		$this->assertSame( '1970-01-01T00:00:00Z', Cursor::from_array( false )->timestamp );
	}
}
