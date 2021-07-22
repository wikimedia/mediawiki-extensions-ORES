<?php

namespace ORES\Tests;

use MediaWiki\MediaWikiServices;
use MediaWikiLangTestCase;
use MWException;
use ORES\Storage\HashModelLookup;
use ORES\Storage\SqlScoreStorage;
use Psr\Log\NullLogger;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Storage\SqlScoreStorage
 */
class SqlScoreStorageTest extends MediaWikiLangTestCase {

	private const GOODFAITH = 1;
	private const REVERTED = 2;
	private const DAMAGING = 3;
	private const ARTICLEQUALITY = 4;

	/**
	 * @var SqlScoreStorage
	 */
	protected $storage;

	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'ores_classification';
		$this->tablesUsed[] = 'recentchanges';
		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
			'articlequality' => [ 'id' => self::ARTICLEQUALITY, 'version' => '0.0.4' ],
		];
		$this->storage = new SqlScoreStorage(
			MediaWikiServices::getInstance()->getDBLoadBalancer(),
			new HashModelLookup( $modelData ),
			new NullLogger()
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

	public function testStoreScoresCleanupOldScores() {
		$this->setMwGlobals( [
			'wgOresWikiId' => 'wiki',
		] );

		$scores = [
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
				'goodfaith' => [
					'score' => [
						'prediction' => true,
						'probability' => [
							'false' => 0.10,
							'true' => 0.90,
						],
					],
				],
			],
		];

		// Put old score there
		$dbw = \wfGetDB( DB_PRIMARY );
		$dbw->insert(
			'ores_classification',
			[
				[
					'oresc_rev' => '567',
					'oresc_model' => (string)self::GOODFAITH,
					'oresc_class' => '1',
					'oresc_probability' => '0.7',
					'oresc_is_predicted' => '1'
				],
				[
					'oresc_rev' => '567',
					'oresc_model' => (string)self::DAMAGING,
					'oresc_class' => '1',
					'oresc_probability' => '0.6',
					'oresc_is_predicted' => '1'
				],
			],
			__METHOD__
		);
		$user = self::getTestUser();
		$dbw->insert(
			'recentchanges',
			[
				'rc_this_oldid' => '567',
				'rc_cur_id' => 3,
				'rc_last_oldid' => '425',
				'rc_comment_id' => 1,
				'rc_actor' => $user->getUser()->getActorId(),
				'rc_timestamp' => $dbw->timestamp(),
			],
			__METHOD__
		);
		$dbw->insert(
			'recentchanges',
			[
				'rc_this_oldid' => '12345',
				'rc_cur_id' => 3,
				'rc_last_oldid' => '567',
				'rc_comment_id' => 1,
				'rc_actor' => $user->getUser()->getActorId(),
				'rc_timestamp' => $dbw->timestamp(),
			],
			__METHOD__
		);

		$this->storage->storeScores( $scores, null, [ 'goodfaith' ] );

		$expected = [
			(object)[
				'oresc_rev' => '567',
				'oresc_model' => (string)self::DAMAGING,
				'oresc_class' => '1',
				'oresc_probability' => '0.600',
				'oresc_is_predicted' => '1'
			],
			(object)[
				'oresc_rev' => '12345',
				'oresc_model' => (string)self::DAMAGING,
				'oresc_class' => '1',
				'oresc_probability' => '0.067',
				'oresc_is_predicted' => '0'
			],
			(object)[
				'oresc_rev' => '12345',
				'oresc_model' => (string)self::REVERTED,
				'oresc_class' => '1',
				'oresc_probability' => '0.876',
				'oresc_is_predicted' => '1'
			],
			(object)[
				'oresc_rev' => '12345',
				'oresc_model' => (string)self::GOODFAITH,
				'oresc_class' => '1',
				'oresc_probability' => '0.900',
				'oresc_is_predicted' => '1'
			],
		];

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
			[],
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
							'type' => 'YAYError',
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
		$this->expectException( MWException::class );
		$this->expectExceptionMessage( 'processRevisionInvalid failure' );
		$this->storage->storeScores(
			$data,
			static function () {
				throw new MWException( 'processRevisionInvalid failure' );
			}
		);
	}

	public function testPurgeRows() {
		global $wgOresModels;
		$oresModelsCopy = $wgOresModels;
		$oresModelsCopy['articlequality']['enabled'] = true;
		$this->setMwGlobals( [ 'wgOresModels' => $oresModelsCopy ] );

		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->insert(
			'ores_classification',
			[
				[
					'oresc_rev' => '12346',
					'oresc_model' => (string)self::REVERTED,
					'oresc_class' => '1',
					'oresc_probability' => '0.876',
					'oresc_is_predicted' => '1'
				],
				[
					'oresc_rev' => '12346',
					'oresc_model' => (string)self::DAMAGING,
					'oresc_class' => '1',
					'oresc_probability' => '0.067',
					'oresc_is_predicted' => '0'
				],
				[
					'oresc_rev' => '12345',
					'oresc_model' => (string)self::DAMAGING,
					'oresc_class' => '1',
					'oresc_probability' => '0.067',
					'oresc_is_predicted' => '0'
				],
				[
					'oresc_rev' => '12344',
					'oresc_model' => (string)self::REVERTED,
					'oresc_class' => '1',
					'oresc_probability' => '0.876',
					'oresc_is_predicted' => '1'
				],
				[
					'oresc_rev' => '12344',
					'oresc_model' => (string)self::GOODFAITH,
					'oresc_class' => '1',
					'oresc_probability' => '0.86',
					'oresc_is_predicted' => '1'
				],
				[
					'oresc_rev' => '12344',
					'oresc_model' => (string)self::ARTICLEQUALITY,
					'oresc_class' => '1',
					'oresc_probability' => '0.86',
					'oresc_is_predicted' => '1'
				],
			]
		);

		$this->storage->purgeRows( [ 12344, 12346 ] );

		$res = wfGetDB( DB_REPLICA )->select(
			'ores_classification',
			[
				'oresc_rev',
				'oresc_model',
				'oresc_class',
				'oresc_probability',
				'oresc_is_predicted'
			],
			'',
			__METHOD__
		);

		$expected = [
			(object)[
				'oresc_rev' => '12345',
				'oresc_model' => (string)self::DAMAGING,
				'oresc_class' => '1',
				'oresc_probability' => '0.067',
				'oresc_is_predicted' => '0'
			],
			(object)[
				'oresc_rev' => '12344',
				'oresc_model' => (string)self::ARTICLEQUALITY,
				'oresc_class' => '1',
				'oresc_probability' => '0.860',
				'oresc_is_predicted' => '1'
			]
		];

		$this->assertEquals( $expected, iterator_to_array( $res, false ) );
	}

}
