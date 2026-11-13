<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Sync\Cursor;
use ResoIdx\Sync\DemoSource;
use ResoIdx\Sync\Mapper;
use ResoIdx\Sync\Syncer;
use ResoIdx\Tests\Support\MemoryStore;

final class DemoSourceTest extends TestCase {

	private function source(): DemoSource {
		return new DemoSource( dirname( __DIR__, 2 ) . '/data/demo-listings.json' );
	}

	public function test_demo_feed_runs_through_the_real_pipeline(): void {
		$store  = new MemoryStore();
		$syncer = new Syncer( $this->source(), $store, new Mapper() );
		$result = $syncer->run( Cursor::start(), 25, 10 );

		$this->assertTrue( $result->done );
		$this->assertGreaterThan( 40, $store->count() );
		foreach ( $store->rows as $row ) {
			$this->assertSame( 'Active', $row['status'] );
			$this->assertStringStartsWith( DemoSource::PHOTO_SCHEME, $row['photos'][0], 'Stored without a site URL.' );
		}
	}

	public function test_demo_photos_resolve_against_the_current_site(): void {
		$base = 'https://playground.test/scope:abc/wp-content/plugins/reso-idx/';
		$this->assertSame( $base . 'assets/demo/house-a.svg', DemoSource::photo_url( 'reso-idx-demo://house-a.svg', $base ) );
		$this->assertSame( $base . 'assets/demo/passwd', DemoSource::photo_url( 'reso-idx-demo://../../etc/passwd', $base ), 'Only a file name is kept.' );
		$this->assertSame( 'https://cdn.test/a.jpg', DemoSource::photo_url( 'https://cdn.test/a.jpg', $base ) );
	}

	public function test_demo_feed_includes_compliance_cases(): void {
		$store = new MemoryStore();
		( new Syncer( $this->source(), $store, new Mapper() ) )->run( Cursor::start(), 100 );

		$hidden = array_filter( $store->rows, static fn ( $r ) => 1 === $r['address_hidden'] );
		$this->assertNotEmpty( $hidden, 'Some demo listings withhold their address.' );
		$this->assertArrayNotHasKey( 'DEMO00029', $store->rows, 'Listings opted out of internet display are skipped.' );
	}
}
