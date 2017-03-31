<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use ORES;
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
		return $this->getMockBuilder( 'Psr\Log\LoggerInterface' )
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

	public function testGetThresholds_damaging() {
		$api = $this->getMockBuilder( 'ORES\Api' )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'damaging' )
			->willReturn( [
				'test_stats' => [
					'recall_at_precision(min_precision=0.15)' => [
						'true' => [ 'threshold' => 0.281 ], // maybebad min
					],
					'recall_at_precision(min_precision=0.45)' => [
						'true' => [ 'threshold' => 0.831 ], // likelybad min
					],
					'recall_at_precision(min_precision=0.9)' => [
						'true' => [ 'threshold' => 0.945 ], // verylikelybad min
					],
					'recall_at_precision(min_precision=0.98)' => [
						'false' => [ 'threshold' => 0.259 ], // likelygood max
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
				'likelygood' => [
					'min' => 0,
					'max' => 0.741, // 1-0.259
				],
				'maybebad' => [
					'min' => 0.281,
					'max' => 1,
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

	public function testGetThresholds_goodfaith() {
		$api = $this->getMockBuilder( 'ORES\Api' )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'goodfaith' )
			->willReturn( [
				'test_stats' => [
					'recall_at_precision(min_precision=0.15)' => [
						'false' => [ 'threshold' => 0.322 ], // maybebad max
					],
					'recall_at_precision(min_precision=0.45)' => [
						'false' => [ 'threshold' => 0.808 ], // bad max
					],
					'recall_at_precision(min_precision=0.98)' => [
						'true' => [ 'threshold' => 0.24 ], // good min
					],
				],
			] );

		$stats = new ORES\Stats(
			$api,
			WANObjectCache::newEmpty(),
			LoggerFactory::getInstance( 'test' )
		);

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals(
			$thresholds,
			[
				'good' => [
					'min' => 0.24,
					'max' => 1,
				],
				'maybebad' => [
					'min' => 0,
					'max' => 0.678, // 1-0.322
				],
				'bad' => [
					'min' => 0,
					'max' => 0.192, // 1-0.808
				]
			]
		);
	}

	public function testGetThresholds_statNotFound() {
		$api = $this->getMockBuilder( 'ORES\Api' )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'goodfaith' )
			->willReturn( [
				'test_stats' => [
					'some_other_stats' => [
						'false' => [ 'recall' => 0.1234 ],
					],
				],
			] );

		$logger = $this->getLoggerMock();
		$logger->expects( $this->exactly( 3 ) )->method( 'warning' );

		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals(
			$thresholds,
			[
				'good' => [
					'min' => 0.35,
					'max' => 1,
				],
				'maybebad' => [
					'min' => 0,
					'max' => 0.65,
				],
				'bad' => [
					'min' => 0,
					'max' => 0.15,
				]
			]
		);
	}

	public function testGetThresholds_modelConfigNotFound() {
		$api = $this->getMockBuilder( 'ORES\Api' )->getMock();
		$logger = $this->getLoggerMock();
		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'unknown_model' );

		$this->assertEquals(
			$thresholds,
			[]
		);
	}

	public function testGetThresholds_everythingGoesWrong() {
		$api = $this->getMockBuilder( 'ORES\Api' )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'goodfaith' )
			->willReturn( 'this is not the stat object you were expecting...' );

		$logger = $this->getLoggerMock();
		$logger->expects( $this->exactly( 3 ) )->method( 'warning' );

		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals(
			$thresholds,
			[
				'good' => [
					'min' => 0.35,
					'max' => 1,
				],
				'maybebad' => [
					'min' => 0,
					'max' => 0.65,
				],
				'bad' => [
					'min' => 0,
					'max' => 0.15,
				]
			]
		);
	}

	public function testGetThresholds_everythingWouldHaveGoneWrong() {
		$api = $this->getMockBuilder( 'ORES\Api' )->getMock();
		$api->method( 'request' )
			->with( [ 'model_info' => 'test_stats' ], 'goodfaith' )
			->willReturn( 'this is not the stat object you were expecting...' );

		$logger = $this->getLoggerMock();

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				"goodfaith" => [
					"good" => [ "min" => 0.7, "max" => 1 ],
					"maybebad" => [ "min" => 0, "max" => 0.69 ],
					"bad" => [ "min" => 0, "max" => 0.25 ],
				],
			],
		] );

		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'goodfaith', false );

		$this->assertEquals(
			$thresholds,
			[
				'good' => [
					'min' => 0.7,
					'max' => 1,
				],
				'maybebad' => [
					'min' => 0,
					'max' => 0.69,
				],
				'bad' => [
					'min' => 0,
					'max' => 0.25,
				]
			]
		);
	}

}
