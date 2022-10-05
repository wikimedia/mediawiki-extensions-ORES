<?php

namespace ORES\Tests;

use HashBagOStuff;
use MediaWiki\Logger\LoggerFactory;
use NullStatsdDataFactory;
use ORES\ORESService;
use ORES\Storage\HashModelLookup;
use ORES\Storage\ThresholdLookup;
use ORES\ThresholdParser;
use Psr\Log\LoggerInterface;
use WANObjectCache;

/**
 * @group ORES
 * @covers ORES\Storage\ThresholdLookup
 */
class ThresholdLookupTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [],
		] );
	}

	private function getLoggerMock() {
		return $this->getMockBuilder( LoggerInterface::class )
			->onlyMethods( [
				'emergency',
				'alert',
				'critical',
				'error',
				'warning',
				'notice',
				'info',
				'debug',
				'log'
			] )
			->getMock();
	}

	private function getNewThresholdLookup( $oresService = null, $logger = null ) {
		if ( $oresService === null ) {
			$oresService = $this->createMock( ORESService::class );
		}

		if ( $logger === null ) {
			$logger = $this->getLoggerMock();
		}

		$modelData = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 3, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 4, 'version' => '0.0.3' ],
		];

		return new ThresholdLookup(
			new ThresholdParser( $this->getLoggerMock() ),
			new HashModelLookup( $modelData ),
			$oresService,
			WANObjectCache::newEmpty(),
			$logger,
			new NullStatsdDataFactory()
		);
	}

	public function testGetThresholds_modelConfigNotFound() {
		$stats = $this->getNewThresholdLookup();

		$thresholds = $stats->getThresholds( 'unknown_model' );

		$this->assertEquals(
			[],
			$thresholds
		);
	}

	public function testGetThresholds_everythingGoesWrong() {
		$oresService = $this->createMock( ORESService::class );
		$oresService->expects( $this->once() )
			->method( 'request' )
			->with( [
				'models' => 'goodfaith',
				'model_info' => 'statistics.thresholds.true."some_stat @ foo"'
					. '|statistics.thresholds.false."some_other_stat @ bar"' ] )
			->willReturn( 'this is not the stat object you were expecting...' );

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'goodfaith' => [
					'level1' => [
						'min' => 'some_stat @ foo',
						'max' => 'some_other_stat @ bar'
					],
				]
			],
		] );

		$stats = $this->getNewThresholdLookup( $oresService );

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals( [], $thresholds );
	}

	public function testGetThresholds_newFiltersConfig() {
		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'damaging' => [
					'verylikelygood' => [ 'min' => 0, 'max' => 'maximum recall @ precision >= 0.98' ],
					'maybebad' => false,
					'likelybad' => [ 'min' => 0.831, 'max' => 1 ],
					'verylikelybad' => [ 'min' => 'maximum recall @ precision >= 0.9', 'max' => 1 ],
				],
			],
			'wgOresWikiId' => 'wiki',
		] );

		$oresService = $this->createMock( ORESService::class );
		$oresService->expects( $this->once() )
			->method( 'request' )
			->with( [
				'models' => 'damaging',
				'model_info' => 'statistics.thresholds.false."maximum recall @ precision >= 0.98"'
					. '|statistics.thresholds.true."maximum recall @ precision >= 0.9"' ] )
			->willReturn( [ 'wiki' => [ 'models' => [ 'damaging' =>
				[ 'statistics' => [ 'thresholds' => [
					'true' => [
						[
							'threshold' => 0.945, // verylikelybad min
						],
					],
					'false' => [
						[
							'threshold' => 0.259, // verylikelygood max
						],
					],
			] ] ] ] ] ] );

		$stats = $this->getNewThresholdLookup( $oresService, LoggerFactory::getInstance( 'test' ) );
		$thresholds = $stats->getThresholds( 'damaging' );

		$this->assertEquals(
			[
				'verylikelygood' => [
					'min' => 0,
					'max' => 0.741,
				],
				'likelybad' => [
					'min' => 0.831,
					'max' => 1,
				],
				'verylikelybad' => [
					'min' => 0.945,
					'max' => 1,
				],
			],
			$thresholds
		);
	}

	public function testCacheVersion() {
		$filtersThresholds = [
			'damaging' => [
				'verylikelygood' => [ 'min' => 0, 'max' => 'maximum recall @ precision >= 0.98' ],
				'maybebad' => false,
				'likelybad' => [ 'min' => 0.831, 'max' => 1 ],
				'verylikelybad' => [ 'min' => 'maximum recall @ precision >= 0.9', 'max' => 1 ],
			],
		];
		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => $filtersThresholds,
			'wgOresWikiId' => 'wiki',
		] );

		$oresService = $this->createMock( ORESService::class );
		$oresService->expects( $this->once() )
			->method( 'request' )
			->with( [
				'models' => 'damaging',
				'model_info' => 'statistics.thresholds.false."maximum recall @ precision >= 0.98"'
					. '|statistics.thresholds.true."maximum recall @ precision >= 0.9"' ] )
			->willReturn( [ 'wiki' => [ 'models' => [ 'damaging' =>
				[ 'statistics' => [ 'thresholds' => [
					'true' => [ [ 'threshold' => 0.945 ] ],
					'false' => [ [ 'threshold' => 0.259 ] ],
				] ] ] ] ] ] );

		$modelData = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 3, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 4, 'version' => '0.0.3' ],
		];

		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$thresholdLookup = new ThresholdLookup(
			new ThresholdParser( $this->getLoggerMock() ),
			new HashModelLookup( $modelData ),
			$oresService,
			$cache,
			$this->getLoggerMock(),
			new NullStatsdDataFactory()
		);

		$thresholdLookup->getThresholds( 'damaging' );

		$expected = [
			'false' => [ 'maximum recall @ precision >= 0.98' => [ 'threshold' => 0.259 ] ],
			'true' => [ 'maximum recall @ precision >= 0.9' => [ 'threshold' => 0.945 ] ]
		];
		$this->assertEquals(
			$expected,
			$cache->get(
				'local:ores_threshold_statistics:damaging:0.0.2:1:' .
				md5( json_encode( $filtersThresholds['damaging'] ) )
			)
		);
	}

}
