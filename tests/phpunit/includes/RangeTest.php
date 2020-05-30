<?php

namespace ORES\Tests;

use ORES\Range;

/**
 * @group ORES
 * @covers ORES\Range
 */
class RangeTest extends \PHPUnit\Framework\TestCase {

	public function testConstructor() {
		$r = new Range( 1, 2 );

		$this->assertSame( 1, $r->getMin() );
		$this->assertSame( 2, $r->getMax() );
	}

	public function testConstructorEx() {
		$this->expectException( \DomainException::class );
		$r = new Range( 3, 2 );
	}

	public function overlapsProvider() {
		return [
			[ [ 1, 3 ], [ 2, 4 ], true ],
			[ [ 1, 2 ], [ 2, 4 ], true ],
			[ [ 1, 5 ], [ 2, 3 ], true ],
			[ [ 1, 1 ], [ 1, 3 ], true ],
			[ [ 0.1, 0.35 ], [ 0.29, 1 ], true ],
			[ [ 0.1, 0.35 ], [ 0.56, 1 ], false ],
			[ [ 1, 2 ], [ 3, 4 ], false ],
		];
	}

	/**
	 * @dataProvider overlapsProvider
	 */
	public function testOverlaps( array $r1, array $r2, $expectedOverlap ) {
		$r1 = new Range( $r1[0], $r1[1] );
		$r2 = new Range( $r2[0], $r2[1] );
		$this->assertEquals( $expectedOverlap, $r1->overlaps( $r2 ) );
		$this->assertEquals( $expectedOverlap, $r2->overlaps( $r1 ) );
	}

	public function combineWithProvider() {
		return [
			[ [ 1, 2 ], [ 3, 4 ], [ 1, 4 ] ],
			[ [ 4, 44 ], [ 11, 88 ], [ 4, 88 ] ],
			[ [ 1, 2 ], [ 4, 5 ], [ 1, 5 ] ],
		];
	}

	/**
	 * @dataProvider combineWithProvider
	 */
	public function testCombineWith( array $r1, array $r2, $expectedRange ) {
		$r1 = new Range( $r1[0], $r1[1] );
		$r2 = new Range( $r2[0], $r2[1] );

		$r1->combineWith( $r2 );

		$this->assertEquals( $expectedRange[0], $r1->getMin() );
		$this->assertEquals( $expectedRange[1], $r1->getMax() );
	}

}
