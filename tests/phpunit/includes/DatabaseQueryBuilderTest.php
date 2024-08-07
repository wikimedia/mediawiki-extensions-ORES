<?php

namespace ORES\Tests;

use ORES\Storage\DatabaseQueryBuilder;
use ORES\Storage\ThresholdLookup;
use Wikimedia\Rdbms\Expression;

/**
 * @group ORES
 * @group Database
 * @covers \ORES\Storage\DatabaseQueryBuilder
 */
class DatabaseQueryBuilderTest extends \MediaWikiIntegrationTestCase {

	private function getNewDatabaseQueryBuilder( $thresholdsConfig = [] ) {
		$thresholdLookup = $this->createMock( ThresholdLookup::class );

		$thresholdLookup->method( 'getThresholds' )
			->willReturn( $thresholdsConfig );

		return new DatabaseQueryBuilder( $thresholdLookup, $this->getDb() );
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

		$clauses[] = ( new Expression( 'ores_model_cls.oresc_probability', '>=', 0 ) )
				->and( 'ores_model_cls.oresc_probability', '<=', 0.3333 );
		$clauses[] = ( new Expression( 'ores_model_cls.oresc_probability', '>=', 0.6667 ) )
				->and( 'ores_model_cls.oresc_probability', '<=', 1 );

		$this->assertEquals(
			$this->getDb()->orExpr( $clauses ),
			$whereClause
		);
	}

	public function testBuildDiscreteQuery() {
		$this->overrideConfigValue( 'OresModelClasses', [
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
			'(ores_model_cls.oresc_is_predicted = 1 AND ores_model_cls.oresc_class IN (0,2))',
			$whereClause->toSql( $this->getDb() )
		);
	}

}
