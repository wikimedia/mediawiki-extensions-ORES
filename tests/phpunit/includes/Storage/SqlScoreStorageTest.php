<?php

namespace ORES\Tests;

use MediaWiki\MediaWikiServices;
use MediaWikiLangTestCase;
use MWException;
use ORES\Storage\HashModelLookup;
use ORES\Storage\SqlScoreStorage;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Storage\SqlScoreStorage
 */
class SqlScoreStorageTest extends MediaWikiLangTestCase {

	const GOODFAITH = 1;
	const REVERTED = 2;
	const DAMAGING = 3;

	/**
	 * @var SqlScoreStorage
	 */
	protected $storage;

	protected function setUp() {
		parent::setUp();

		$this->tablesUsed[] = 'ores_classification';
		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
		];
		$this->storage = new SqlScoreStorage(
			MediaWikiServices::getInstance()->getDBLoadBalancer(),
			new HashModelLookup( $modelData )
		);
	}

	public function storeScoresProvider() {
		return [
			[
				[
					'wiki' => [
						'models' => [
							'damaging' => [
								'version' => '0.0.2',
							],
							'goodfaith' => [
								'version' => '0.0.3',
							],
						],
						'scores' => [
							'12345' => [
								'damaging' => [
									'score' => [
										'prediction' => false,
										'probability' => [
											'false' => 0.933,
											'true' => 0.067,
										],
									],
								],
								'reverted' => [
									'score' => [
										'prediction' => true,
										'probability' => [
											'false' => 0.124,
											'true' => 0.876,
										],
									],
								],
							],
						],
					],
				],
				[
					(object)[
						'oresc_rev' => '12345',
						'oresc_model' => (string)self::REVERTED,
						'oresc_class' => '1',
						'oresc_probability' => '0.876',
						'oresc_is_predicted' => '1'
					],
					(object)[
						'oresc_rev' => '12345',
						'oresc_model' => (string)self::DAMAGING,
						'oresc_class' => '1',
						'oresc_probability' => '0.067',
						'oresc_is_predicted' => '0'
					],
				],
				[ 12345 ],
			],
		];
	}

	/**
	 * @dataProvider storeScoresProvider
	 */
	public function testStoreScores( $scores, $expected, $revIds ) {
		$this->setMwGlobals( [
			'wgOresWikiId' => 'wiki',
		] );

		$scores = $scores['wiki']['scores'];

		$this->storage->storeScores( $scores );

		$dbr = \wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'ores_classification',
			[
				'oresc_rev',
				'oresc_model',
				'oresc_class',
				'oresc_probability',
				'oresc_is_predicted'
			],
			[ 'oresc_rev' => $revIds ],
			__METHOD__,
			'ORDER BY oresc_probability'
		);

		$this->assertEquals( $expected, iterator_to_array( $res, false ) );
	}

	public function storeScoresInvalidProvider() {
		return [
			[
				[ 1111 => [
					'non existing model' => [
						'score' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.9, 'false' => 0.1 ]
						],
					],
				] ]
			],
			[
				[ 12345 => [
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
				] ]
			],
			[
				[ 12345 => [
					'reverted' => [
						'score' => [
							'prediction' => false,
							'probability' => [ 'It should fail' => 0.3, 'false' => 0.7 ]
						],
					]
				] ]
			],
		];
	}

	/**
	 * @dataProvider storeScoresInvalidProvider
	 */
	public function testProcessRevisionInvalid( array $data ) {
		$this->setExpectedException( MWException::class, 'processRevisionInvalid failure' );
		$this->storage->storeScores(
			$data,
			function () {
				throw new MWException( 'processRevisionInvalid failure' );
			}
		);
	}

}
