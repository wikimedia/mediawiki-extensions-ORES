<?php

namespace ORES\Tests\Parser;

use InvalidArgumentException;
use MediaWikiLangTestCase;
use ORES\Parser\ScoreParser;
use ORES\Storage\HashModelLookup;

/**
 * @group ORES
 * @covers ORES\Parser\ScoreParser
 */
class ScoreParserTest extends MediaWikiLangTestCase {

	const REVERTED = 2;
	const DAMAGING = 3;
	const GOODFAITH = 4;
	const WP10 = 5;

	public function processRevisionProvider() {
		return [
			[
				[],
				[],
				12345
			],
			[
				[
					'damaging' => [
						'score' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.9, 'false' => 0.1 ]
						],
					],
				],
				[
					[
						'oresc_rev' => 1111,
						'oresc_model' => self::DAMAGING,
						'oresc_class' => 1,
						'oresc_probability' => 0.9,
						'oresc_is_predicted' => true
					],
				],
				1111
			],
			[
				[
					'damaging' => [
						'score' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.6, 'false' => 0.4 ]
						],
					],
					'reverted' => [
						'score' => [
							'prediction' => false,
							'probability' => [ 'true' => 0.3, 'false' => 0.7 ]
						],
					]
				],
				[
					[
						'oresc_rev' => 12345,
						'oresc_model' => self::DAMAGING,
						'oresc_class' => 1,
						'oresc_probability' => 0.6,
						'oresc_is_predicted' => true
					],
					[
						'oresc_rev' => 12345,
						'oresc_model' => self::REVERTED,
						'oresc_class' => 1,
						'oresc_probability' => 0.3,
						'oresc_is_predicted' => false
					],
				],
				12345
			],
			[
				[
					'wp10' => [
						'score' => [
							'prediction' => 'B',
							'probability' => [
								'B' => 0.4624338381531999,
								'C' => 0.050104495503425654,
								'FA' => 0.04630378792818694,
								'GA' => 0.4351923376756259,
								'Start' => 0.004747126844044479,
								'Stub' => 0.0012184138955171303
							]
						],
					]
				],
				[
					[
						'oresc_rev' => 12347,
						'oresc_model' => self::WP10,
						'oresc_class' => 3,
						'oresc_probability' => 0.4624338381531999,
						'oresc_is_predicted' => true
					],
					[
						'oresc_rev' => 12347,
						'oresc_model' => self::WP10,
						'oresc_class' => 2,
						'oresc_probability' => 0.050104495503425654,
						'oresc_is_predicted' => false
					],
					[
						'oresc_rev' => 12347,
						'oresc_model' => self::WP10,
						'oresc_class' => 5,
						'oresc_probability' => 0.04630378792818694,
						'oresc_is_predicted' => false
					],
					[
						'oresc_rev' => 12347,
						'oresc_model' => self::WP10,
						'oresc_class' => 4,
						'oresc_probability' => 0.4351923376756259,
						'oresc_is_predicted' => false
					],
					[
						'oresc_rev' => 12347,
						'oresc_model' => self::WP10,
						'oresc_class' => 1,
						'oresc_probability' => 0.004747126844044479,
						'oresc_is_predicted' => false
					],
					[
						'oresc_rev' => 12347,
						'oresc_model' => self::WP10,
						'oresc_class' => 0,
						'oresc_probability' => 0.0012184138955171303,
						'oresc_is_predicted' => false
					],
				],
				12347
			],
			[
				[
					'wp10' => [
						'score' => [
							'prediction' => 'B',
							'probability' => [
								'B' => 0.4624338381531999,
								'C' => 0.050104495503425654,
								'FA' => 0.04630378792818694,
								'GA' => 0.4351923376756259,
								'Start' => 0.004747126844044479,
								'Stub' => 0.0012184138955171303
							]
						],
					]
				],
				[
					[
						'oresc_rev' => 12348,
						'oresc_model' => self::WP10,
						'oresc_class' => 0,
						'oresc_probability' => 0.57742432044232228,
						'oresc_is_predicted' => false
					],
				],
				12348,
				[ 'wp10' ]
			]
		];
	}

	/**
	 * @dataProvider processRevisionProvider
	 */
	public function testProcessRevision(
		array $revisionData,
		array $expected,
		$revId,
		array $aggregatedModels = []
	) {
		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
			'wp10' => [ 'id' => self::WP10, 'version' => '0.0.4' ]
		];
		$modelClasses = [
			'damaging' => [ 'false' => 0, 'true' => 1 ],
			'reverted' => [ 'false' => 0, 'true' => 1 ],
			'goodfaith' => [ 'false' => 0, 'true' => 1 ],
			'wp10' => [ 'Stub' => 0, 'Start' => 1, 'C' => 2, 'B' => 3, 'GA' => 4, 'FA' => 5 ],
		];
		$scoreParser = new ScoreParser(
			new HashModelLookup( $modelData ),
			$modelClasses,
			$aggregatedModels
		);
		$data = $scoreParser->processRevision( $revId, $revisionData );
		$this->assertEquals( $expected, $data );
	}

	public function processRevisionInvalidProvider() {
		return [
			[
				[
					'non existing model' => [
						'score' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.9, 'false' => 0.1 ]
						],
					],
				],
				1111
			],
			[
				[
					'damaging' => [
						'error' => [
							'message' => 'YAY, it failed',
							'type' => 'YAYError',
						],
					],
					'reverted' => [
						'score' => [
							'prediction' => false,
							'probability' => [ 'true' => 0.3, 'false' => 0.7 ]
						],
					]
				],
				12345
			],
			[
				[
					'reverted' => [
						'score' => [
							'prediction' => false,
							'probability' => [ 'It should fail' => 0.3, 'false' => 0.7 ]
						],
					]
				],
				12345
			],
		];
	}

	/**
	 * @dataProvider processRevisionInvalidProvider
	 */
	public function testProcessRevisionInvalid( array $revisionData, $revId ) {
		$this->setExpectedException( InvalidArgumentException::class );
		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
		];
		$modelClasses = [
			'reverted' => [ 'true' => 1, 'false' => 0 ],
			'damaging' => [ 'true' => 1, 'false' => 0 ],
			'goodfaith' => [ 'true' => 1, 'false' => 0 ]
		];
		$scoreParser = new ScoreParser( new HashModelLookup( $modelData ), $modelClasses );
		$scoreParser->processRevision( $revId, $revisionData );
	}

}
