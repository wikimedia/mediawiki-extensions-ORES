<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use ORES;
use ORES\Api;
use Psr\Log\LoggerInterface;
use WANObjectCache;

/**
 * @group ORES
 * @covers ORES\Stats
 */
class StatsTest extends \MediaWikiTestCase {

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [],
		] );
	}

	private function getLoggerMock() {
		return $this->getMockBuilder( LoggerInterface::class )
			->setMethods( [
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

	public function testGetThresholds_modelConfigNotFound() {
		$api = $this->getMockBuilder( Api::class )->getMock();
		$logger = $this->getLoggerMock();
		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'unknown_model' );

		$this->assertEquals(
			$thresholds,
			[]
		);
	}

	public function testGetThresholds_everythingGoesWrong() {
		$api = $this->getMockBuilder( Api::class )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'goodfaith' )
			->willReturn( 'this is not the stat object you were expecting...' );

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'goodfaith' => [
					'level1' => [ 'min' => 'some_stat', 'max' => 'some_other_stat' ],
				]
			],
		] );

		$logger = $this->getLoggerMock();
		$logger->expects( $this->exactly( 2 ) )->method( 'warning' );

		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals(
			$thresholds,
			[]
		);
	}

	public function testGetThresholds_filtersConfig() {
		$api = $this->getMockBuilder( Api::class )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'damaging' )
			->willReturn( [
				'test_stats' => [
					'recall_at_precision(min_precision=0.9)' => [
						'true' => [ 'threshold' => 0.945 ], // verylikelybad min
					],
					'recall_at_precision(min_precision=0.98)' => [
						'false' => [ 'threshold' => 0.259 ], // verylikelygood max
					],
				],
			] );

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'damaging' => [
					'verylikelygood' => [ 'min' => 0, 'max' => 'recall_at_precision(min_precision=0.98)' ],
					'maybebad' => false,
					'likelybad' => [ 'min' => 0.831, 'max' => 1 ],
					'verylikelybad' => [ 'min' => 'recall_at_precision(min_precision=0.9)', 'max' => 1 ],
				],
			],
		] );

		$stats = new ORES\Stats(
			$api,
			WANObjectCache::newEmpty(),
			LoggerFactory::getInstance( 'test' )
		);

		$thresholds = $stats->getThresholds( 'damaging' );

		$this->assertEquals(
			$thresholds,
			[
				'verylikelygood' => [
					'min' => 0,
					'max' => 0.741, // 1-0.259
				],
				'likelybad' => [
					'min' => 0.831,
					'max' => 1,
				],
				'verylikelybad' => [
					'min' => 0.945,
					'max' => 1,
				],
			]
		);
	}

}
