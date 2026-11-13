<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Storage\SearchCriteria;

final class SearchCriteriaTest extends TestCase {

	public function test_parses_and_clamps_input(): void {
		$c = SearchCriteria::from_array(
			array(
				'q'         => "  Maple\x00 ",
				'min_price' => '400000',
				'max_price' => 'lots',
				'beds'      => '99',
				'baths'     => '-1',
				'sort'      => 'price_asc',
				'page'      => '3',
				'per_page'  => '500',
			)
		);
		$this->assertSame( 'Maple', $c->q );
		$this->assertSame( 400000, $c->min_price );
		$this->assertNull( $c->max_price );
		$this->assertSame( 20, $c->beds );
		$this->assertNull( $c->baths );
		$this->assertSame( 'price_asc', $c->sort );
		$this->assertSame( 3, $c->page );
		$this->assertSame( 48, $c->per_page );
	}

	public function test_unknown_sort_falls_back_to_newest(): void {
		$c = SearchCriteria::from_array( array( 'sort' => 'price; DROP TABLE wp_users' ) );
		$this->assertSame( 'newest', $c->sort );
		$this->assertStringStartsWith( 'listed_at DESC', $c->order_by() );
	}

	public function test_builds_where_clause_with_placeholders(): void {
		list( $sql, $args ) = SearchCriteria::from_array( array( 'q' => '50%_off', 'type' => 'Condominium', 'beds' => '2' ) )->where();

		$this->assertStringContainsString( 'address LIKE %s', $sql );
		$this->assertStringContainsString( '(property_type = %s OR property_subtype = %s)', $sql );
		$this->assertStringContainsString( 'beds >= %d', $sql );
		$this->assertSame( '%50\%\_off%', $args[0], 'LIKE wildcards in input are escaped.' );
		$this->assertSame( 2, end( $args ) );
		$this->assertSame( substr_count( $sql, '%s' ) + substr_count( $sql, '%d' ), count( $args ) );
	}

	public function test_accepts_valid_map_bounds_only(): void {
		$this->assertSame( array( 45.3, -75.8, 45.5, -75.6 ), SearchCriteria::from_array( array( 'bounds' => '45.3,-75.8,45.5,-75.6' ) )->bounds );
		$this->assertNull( SearchCriteria::from_array( array( 'bounds' => '45.5,-75.8,45.3,-75.6' ) )->bounds, 'South must be below north.' );
		$this->assertNull( SearchCriteria::from_array( array( 'bounds' => '1,2,3' ) )->bounds );
		$this->assertNull( SearchCriteria::from_array( array( 'bounds' => 'a,b,c,d' ) )->bounds );
	}

	public function test_query_args_leave_out_defaults(): void {
		$this->assertSame( array( 'city' => 'Ottawa', 'beds' => 3 ), SearchCriteria::from_array( array( 'city' => 'Ottawa', 'beds' => '3', 'sort' => 'newest' ) )->to_query_args() );
	}
}
