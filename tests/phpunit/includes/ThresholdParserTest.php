<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use ORES\ThresholdParser;
use Psr\Log\LoggerInterface;

/**
 * @group ORES
 * @covers ORES\ThresholdParser
 */
class ThresholdParserTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'damaging' => [
					'verylikelygood' => [ 'min' => 0, 'max' => 'maximum recall @ precision >= 0.98' ],
					'maybebad' => false,
					'likelybad' => [ 'min' => 0.81, 'max' => 1 ],
					'verylikelybad' => [ 'min' => 'maximum recall @ precision >= 0.9', 'max' => 1 ],
				],
			],
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

	/**
	 * @dataProvider provideTestParseThresholds
	 */
	public function testParseThresholds( $expected, $data, $model ) {
		$thresholdParser = new ThresholdParser( $this->getLoggerMock() );
		$thresholds = $thresholdParser->parseThresholds( $data, $model );
		$this->assertSame( $expected, $thresholds );
	}

	public function provideTestParseThresholds() {
		$thresholdData1 = [
			'false' => [ 'maximum recall @ precision >= 0.98' => [ 'threshold' => 0.259 ] ],
			'true' => [ 'maximum recall @ precision >= 0.9' => [ 'threshold' => 0.945 ] ]
		];
		$expected1 = [
			'verylikelygood' => [ 'min' => 0, 'max' => 0.741 ],
			'likelybad' => [ 'min' => 0.81, 'max' => 1 ],
			'verylikelybad' => [ 'min' => 0.945, 'max' => 1 ]
		];

		$thresholdData2 = [
			'true' => [ 'maximum recall @ precision >= 0.9' => [ 'threshold' => 0.945 ] ],
			'false' => []
		];
		$expected2 = [
			'likelybad' => [ 'min' => 0.81, 'max' => 1 ],
			'verylikelybad' => [ 'min' => 0.945, 'max' => 1 ]
		];

		return [
			[ [ 'likelybad' => [ 'min' => 0.81,  'max' => 1 ] ], [], 'damaging' ],
			[ $expected1, $thresholdData1, 'damaging' ],
			[ $expected2, $thresholdData2, 'damaging' ],
		];
	}

	public function testGetFiltersConfig_oldFiltersConfig() {
		$thresholdParser = new ThresholdParser( LoggerFactory::getInstance( 'test' ) );
		$thresholds = $thresholdParser->getFiltersConfig( 'damaging' );

		$this->assertEquals(
			[
				'verylikelygood' => [
					'min' => 0,
					'max' => 'maximum recall @ precision >= 0.98',
				],
				'likelybad' => [
					'min' => 0.81,
					'max' => 1,
				],
				'verylikelybad' => [
					'min' => 'maximum recall @ precision >= 0.9',
					'max' => 1,
				],
				'maybebad' => false,
			],
			$thresholds
		);
	}

	public function testGetFiltersConfig_newFiltersConfig() {
		$thresholdParser = new ThresholdParser( LoggerFactory::getInstance( 'test' ) );
		$thresholds = $thresholdParser->getFiltersConfig( 'damaging' );

		$this->assertEquals(
			[
				'verylikelygood' => [
					'min' => 0,
					'max' => 'maximum recall @ precision >= 0.98',
				],
				'likelybad' => [
					'min' => 0.81,
					'max' => 1,
				],
				'verylikelybad' => [
					'min' => 'maximum recall @ precision >= 0.9',
					'max' => 1,
				],
				'maybebad' => false,
			],
			$thresholds
		);
	}

}
