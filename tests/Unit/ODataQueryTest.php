<?php
/**
 * @package ResoIdx
 */

namespace ResoIdx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ResoIdx\Api\ODataQuery;

final class ODataQueryTest extends TestCase {

	public function test_builds_all_parameters(): void {
		$params = ODataQuery::create()
			->where( "City eq 'Ottawa'" )
			->where_in( 'StandardStatus', array( 'Active', 'Pending' ) )
			->select( array( 'ListingKey', 'ListPrice' ) )
			->order_by( 'ModificationTimestamp,ListingKey' )
			->top( 50 )
			->skip( 100 )
			->expand( 'Media' )
			->to_params();

		$this->assertSame( "(City eq 'Ottawa') and (StandardStatus eq 'Active' or StandardStatus eq 'Pending')", $params['$filter'] );
		$this->assertSame( 'ListingKey,ListPrice', $params['$select'] );
		$this->assertSame( 'ModificationTimestamp,ListingKey', $params['$orderby'] );
		$this->assertSame( '50', $params['$top'] );
		$this->assertSame( '100', $params['$skip'] );
		$this->assertSame( 'Media', $params['$expand'] );
	}

	public function test_single_clause_is_not_wrapped(): void {
		$this->assertSame( "City eq 'Ottawa'", ODataQuery::create()->where( "City eq 'Ottawa'" )->to_params()['$filter'] );
	}

	public function test_or_clause_is_wrapped_when_combined(): void {
		$filter = ODataQuery::create()->where( "City eq 'A' or City eq 'B'" )->where( 'ListPrice gt 1' )->to_params()['$filter'];
		$this->assertSame( "(City eq 'A' or City eq 'B') and (ListPrice gt 1)", $filter );
	}

	public function test_empty_clauses_are_ignored(): void {
		$this->assertArrayNotHasKey( '$filter', ODataQuery::create()->where( '  ' )->where_in( 'City', array() )->to_params() );
	}

	public function test_literals_escape_single_quotes(): void {
		$this->assertSame( "'O''Brien Street'", ODataQuery::literal( "O'Brien Street" ) );
		$filter = ODataQuery::create()->where_in( 'StreetName', array( "O'Brien" ) )->to_params()['$filter'];
		$this->assertSame( "StreetName eq 'O''Brien'", $filter );
	}

	public function test_rejects_field_names_that_are_not_identifiers(): void {
		$this->expectException( \InvalidArgumentException::class );
		ODataQuery::create()->select( array( 'ListPrice) or (1 eq 1' ) );
	}

	public function test_builder_is_immutable(): void {
		$base = ODataQuery::create()->where( 'A eq 1' );
		$base->where( 'B eq 2' );
		$this->assertSame( 'A eq 1', $base->to_params()['$filter'] );
	}

	public function test_datetime_is_utc_and_keeps_fractions(): void {
		$this->assertSame( '2026-11-01T17:00:00Z', ODataQuery::datetime( '2026-11-01T12:00:00-05:00' ) );
		$this->assertSame( '2026-11-01T12:00:00.25Z', ODataQuery::datetime( '2026-11-01T12:00:00.250Z' ) );
	}

	public function test_query_string_encodes_dollar_and_spaces(): void {
		$qs = ODataQuery::create()->where( "City eq 'Ottawa'" )->top( 5 )->to_query_string();
		$this->assertSame( '%24filter=City%20eq%20%27Ottawa%27&%24top=5', $qs );
	}
}
