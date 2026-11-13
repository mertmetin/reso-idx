<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Api\Client;
use ResoIdx\Sync\Cursor;
use ResoIdx\Sync\RemoteSource;
use ResoIdx\Tests\Support\FakeTransport;

final class RemoteSourceTest extends TestCase {

	private function source( FakeTransport $t, string $media = 'none', array $available = array() ): RemoteSource {
		$client = new Client( 'https://api.test/odata/', 'token', $t, 0, static function (): void {} );
		return new RemoteSource( $client, 'Property', "City eq 'Ottawa'", array( 'Active' ), $media, "ImageSizeDescription eq 'Large'", $available );
	}

	private static function row( string $key, string $ts ): array {
		return array( 'ListingKey' => $key, 'ModificationTimestamp' => $ts, 'StandardStatus' => 'Active' );
	}

	public function test_asks_for_changes_since_the_cursor_within_scope(): void {
		$t = new FakeTransport( array( FakeTransport::json( array( 'value' => array() ) ) ) );
		$this->source( $t )->changes( new Cursor( '2026-11-01T12:00:00Z', 'B' ), 100 );

		$this->assertStringContainsString( "\$filter=(City eq 'Ottawa') and (ModificationTimestamp ge 2026-11-01T12:00:00Z)", $t->requests[0] );
		$this->assertStringContainsString( '$orderby=ModificationTimestamp,ListingKey', $t->requests[0] );
		$this->assertStringNotContainsString( 'StandardStatus', $t->requests[0], 'All statuses are fetched so removals are seen.' );
	}

	public function test_drops_records_the_cursor_already_covers(): void {
		$t = new FakeTransport(
			array(
				FakeTransport::json(
					array(
						'value' => array(
							self::row( 'A', '2026-11-01T12:00:00Z' ),
							self::row( 'B', '2026-11-01T12:00:00Z' ),
							self::row( 'C', '2026-11-01T12:00:00Z' ),
							self::row( 'A', '2026-11-01T13:00:00Z' ),
						),
					)
				),
			)
		);
		$rows = $this->source( $t )->changes( new Cursor( '2026-11-01T12:00:00Z', 'B' ), 10 );
		$this->assertSame( array( 'C', 'A' ), array_column( $rows, 'ListingKey' ) );
	}

	public function test_reads_a_whole_timestamp_group_larger_than_a_page(): void {
		$tied = static fn ( array $keys ): array => array_map( static fn ( $k ) => self::row( $k, '2026-11-01T12:00:00Z' ), $keys );
		$t    = new FakeTransport(
			array(
				FakeTransport::json( array( 'value' => $tied( array( 'A', 'B' ) ) ) ),
				FakeTransport::json( array( 'value' => $tied( array( 'A', 'B' ) ) ) ),
				FakeTransport::json( array( 'value' => $tied( array( 'C' ) ) ) ),
			)
		);
		$rows = $this->source( $t )->changes( new Cursor( '2026-11-01T12:00:00Z', 'A' ), 2 );

		$this->assertSame( array( 'B', 'C' ), array_column( $rows, 'ListingKey' ) );
		$this->assertStringContainsString( 'ModificationTimestamp eq 2026-11-01T12:00:00Z', $t->requests[1] );
	}

	public function test_selects_only_fields_the_server_has(): void {
		$t = new FakeTransport( array( FakeTransport::json( array( 'value' => array() ) ) ) );
		$this->source( $t, 'none', array( 'Property' => array( 'ListingKey', 'ListPrice', 'LivingAreaRange', 'SomethingElse' ) ) )->changes( Cursor::start(), 5 );

		$this->assertStringContainsString( '$select=ListingKey,ListPrice,LivingAreaRange&', $t->requests[0] );
		$this->assertStringNotContainsString( 'LivingArea,', $t->requests[0] );
	}

	public function test_attaches_media_from_the_media_resource(): void {
		$t = new FakeTransport(
			array(),
			static function ( string $url ): array {
				if ( str_contains( $url, '/Media?' ) ) {
					return FakeTransport::json(
						array(
							'value' => array(
								array( 'ResourceRecordKey' => 'A', 'MediaURL' => 'https://img.test/a.jpg' ),
								array( 'ResourceRecordKey' => 'B', 'MediaURL' => 'https://img.test/b.jpg' ),
							),
						)
					);
				}
				return FakeTransport::json( array( 'value' => array( self::row( 'A', '2026-11-01T12:00:00Z' ), self::row( 'B', '2026-11-01T12:00:01Z' ) ) ) );
			}
		);
		$rows = $this->source( $t, 'resource' )->changes( Cursor::start(), 10 );

		$this->assertSame( 'https://img.test/a.jpg', $rows[0]['Media'][0]['MediaURL'] );
		$this->assertSame( 'https://img.test/b.jpg', $rows[1]['Media'][0]['MediaURL'] );
		$this->assertStringContainsString( "(ResourceRecordKey eq 'A' or ResourceRecordKey eq 'B') and (ImageSizeDescription eq 'Large')", $t->requests[1] );
	}

	public function test_active_keys_filter_by_status(): void {
		$t = new FakeTransport( array( FakeTransport::json( array( 'value' => array( array( 'ListingKey' => 'A' ), array( 'ListingKey' => 'B' ) ) ) ) ) );
		$this->assertSame( array( 'A', 'B' ), $this->source( $t )->active_keys() );
		$this->assertStringContainsString( "StandardStatus eq 'Active'", $t->requests[0] );
		$this->assertStringContainsString( '$select=ListingKey', $t->requests[0] );
	}
}
