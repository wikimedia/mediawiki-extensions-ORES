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
			]
		];
	}

	/**
	 * @dataProvider processRevisionProvider
	 */
	public function testProcessRevision( $revisionData, $expected, $revId ) {
		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
		];
		$modelClasses = [
			'damaging' => [ 'false' => 0, 'true' => 1 ],
			'reverted' => [ 'false' => 0, 'true' => 1 ],
			'goodfaith' => [ 'false' => 0, 'true' => 1 ],
		];
		$scoreParser = new ScoreParser( new HashModelLookup( $modelData ), $modelClasses );
		$data = $scoreParser->processRevision( $revId, $revisionData );
		$this->assertSame( $expected, $data );
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
	public function testProcessRevisionInvalid( $revisionData, $revId ) {
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
