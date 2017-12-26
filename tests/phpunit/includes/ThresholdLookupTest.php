<?php

namespace ORES\Tests;

use HashBagOStuff;
use MediaWiki\Logger\LoggerFactory;
use ORES\Api;
use ORES\Storage\HashModelLookup;
use ORES\ThresholdLookup;
use Psr\Log\LoggerInterface;
use WANObjectCache;

/**
 * @group ORES
 * @covers ORES\ThresholdLookup
 */
class ThresholdLookupTest extends \MediaWikiTestCase {

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

	private function getNewThresholdLookup( $api = null, $logger = null ) {
		if ( $api === null ) {
			$api = $this->getMockBuilder( Api::class )->getMock();
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
			$api,
			WANObjectCache::newEmpty(),
			$logger,
			new HashModelLookup( $modelData )
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
		$api = $this->getMockBuilder( Api::class )->getMock();
		$api->expects( $this->exactly( 1 ) )
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

		$stats = $this->getNewThresholdLookup( $api );

		$thresholds = $stats->getThresholds( 'goodfaith' );

		$this->assertEquals( [], $thresholds );
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
		$api->expects( $this->exactly( 1 ) )
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

		$stats = $this->getNewThresholdLookup( $api, LoggerFactory::getInstance( 'test' ) );
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
		$api->expects( $this->exactly( 1 ) )
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

		$stats = $this->getNewThresholdLookup( $api, LoggerFactory::getInstance( 'test' ) );
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
		$api->expects( $this->exactly( 1 ) )
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
			$api,
			$cache,
			$this->getLoggerMock(),
			new HashModelLookup( $modelData )
		);

		$thresholdLookup->getThresholds( 'damaging' );

		$expected = [
			'false' => [ 'maximum recall @ precision >= 0.98' => [ 'threshold' => 0.259 ] ],
			'true' => [ 'maximum recall @ precision >= 0.9' => [ 'threshold' => 0.945 ] ]
		];
		$this->assertSame( $expected, $cache->get( 'local:ORES:threshold_statistics:damaging:0.0.2:1' ) );
	}

}
