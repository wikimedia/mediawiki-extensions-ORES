<?php

namespace ORES\Tests;

use ORES\Storage\DatabaseQueryBuilder;
use ORES\Storage\ThresholdLookup;

/**
 * @group ORES
 * @covers ORES\Storage\DatabaseQueryBuilder
 */
class DatabaseQueryBuilderTest extends \MediaWikiIntegrationTestCase {

	private function getNewDatabaseQueryBuilder( $thresholdsConfig = [] ) {
		$thresholdLookup = $this->createMock( ThresholdLookup::class );

		$thresholdLookup->method( 'getThresholds' )
			->willReturn( $thresholdsConfig );

		return new DatabaseQueryBuilder( $thresholdLookup, wfGetDB( DB_PRIMARY ) );
	}

	public function testBuildRangeQuery() {
		$databaseQueryBuilder = $this->getNewDatabaseQueryBuilder( [
				'level1' => [ 'min' => 0, 'max' => 0.3333 ],
				'level2' => [ 'min' => 0.3334, 'max' => 0.6666 ],
				'level3' => [ 'min' => 0.6667, 'max' => 1 ],
		] );

		$whereClause = $databaseQueryBuilder->buildQuery(
			'model',
			[ 'level1', 'level3' ]
		);

		$this->assertEquals(
			'(ores_model_cls.oresc_probability BETWEEN 0 AND 0.3333)' .
			' OR (ores_model_cls.oresc_probability BETWEEN 0.6667 AND 1)',
			$whereClause
		);
	}

	public function testBuildDiscreteQuery() {
		$this->setMwGlobals( 'wgOresModelClasses', [
			'model' => [
				'class0' => 0,
				'class1' => 1,
				'class2' => 2,
			]
		] );
		$databaseQueryBuilder = $this->getNewDatabaseQueryBuilder();

		$whereClause = $databaseQueryBuilder->buildQuery(
			'model',
			[ 'class0', 'class2' ],
			true
		);

		$this->assertEquals(
			'((ores_model_cls.oresc_class = 0)' .
			' OR (ores_model_cls.oresc_class = 2))' .
			' AND (ores_model_cls.oresc_is_predicted = 1)',
			$whereClause
		);
	}

}
