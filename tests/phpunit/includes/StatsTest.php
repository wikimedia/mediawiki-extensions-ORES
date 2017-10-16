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
			[],
			$thresholds
		);
	}

	public function testGetThresholds_everythingGoesWrong() {
		$api = $this->getMockBuilder( Api::class )->getMock();
		$api
			->expects( $this->exactly( 1 ) )
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

		$logger = $this->getLoggerMock();
		// FIXME: Review and check for logging.
		// $logger->expects( $this->exactly( 2 ) )->method( 'warning' );

		$stats = new ORES\Stats( $api, WANObjectCache::newEmpty(), $logger );

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals(
			[],
			$thresholds
		);
	}

	public function testGetThresholds_oldFiltersConfig() {
		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'damaging' => [
					'verylikelygood' => [ 'min' => 0, 'max' => 'recall_at_precision(min_precision=0.98)' ],
					'maybebad' => false,
					'likelybad' => [ 'min' => 0.831, 'max' => 1 ],
					'verylikelybad' => [ 'min' => 'recall_at_precision(min_precision=0.9)', 'max' => 1 ],
				],
			],
			'wgOresWikiId' => 'wiki',
		] );

		$api = $this->getMockBuilder( Api::class )->getMock();
		$api
			->expects( $this->exactly( 1 ) )
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

		$stats = new ORES\Stats(
			$api,
			WANObjectCache::newEmpty(),
			LoggerFactory::getInstance( 'test' )
		);

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

		$api = $this->getMockBuilder( Api::class )->getMock();
		$api
			->expects( $this->exactly( 1 ) )
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

		$stats = new ORES\Stats(
			$api,
			WANObjectCache::newEmpty(),
			LoggerFactory::getInstance( 'test' )
		);

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

	/**
	 * @expectedException \RuntimeException
	 */
	public function testFetchStats_oldServer() {
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

		$api = $this->getMockBuilder( Api::class )->getMock();
		$api
			->expects( $this->exactly( 1 ) )
			->method( 'request' )
			// New syntax request.
			->with( [
				'models' => 'damaging',
				'model_info' => 'statistics.thresholds.false."maximum recall @ precision >= 0.98"'
					. '|statistics.thresholds.true."maximum recall @ precision >= 0.9"' ] )
			// Confused, old server.
			->willReturn( [
				'wiki' => [
					'models' => [
						'damaging' => [],
					]
				]
			] );

		$stats = new ORES\Stats(
			$api,
			WANObjectCache::newEmpty(),
			LoggerFactory::getInstance( 'test' )
		);

		// Make fetchStats accessible.
		$method = new \ReflectionMethod( $stats, 'fetchStats' );
		$method->setAccessible( true );

		// Equivalent to calling $stats->fetchStats.  Should throw an exception.
		$method->invoke( $stats, 'damaging', true );
	}

}
