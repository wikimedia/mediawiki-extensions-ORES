<?php

namespace ORES\Tests;

use MediaWiki\MediaWikiServices;
use MediaWikiLangTestCase;
use ORES\Storage\HashModelLookup;
use ORES\Storage\SqlScoreStorage;
use Psr\Log\NullLogger;
use RuntimeException;

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

		$this->setMwGlobals( [
			'wgOresModels' => [
				'damaging' => [ 'enabled' => true ],
				'goodfaith' => [ 'enabled' => true ],
				'reverted' => [ 'enabled' => true ],
				'articlequality' => [
					'enabled' => true,
					'namespaces' => [ 0 ],
					'cleanParent' => true,
					'keepForever' => true,
				],
				'wp10' => [
					'enabled' => false,
					'namespaces' => [ 0 ],
					'cleanParent' => true,
					'keepForever' => true,
				],
				'draftquality' => [
					'enabled' => false,
					'namespaces' => [ 0 ],
					'types' => [ 1 ],
				],
			]
		] );

		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
			'articlequality' => [ 'id' => self::ARTICLEQUALITY, 'version' => '0.0.4' ],
		];
		$this->storage = new SqlScoreStorage(
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory(),
			new HashModelLookup( $modelData ),
			new NullLogger()
		);
	}

	public static function storeScoresProvider() {
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

		$res = $this->getDb()->newSelectQueryBuilder()
			->select( [
				'oresc_rev',
				'oresc_model',
				'oresc_class',
				'oresc_probability',
				'oresc_is_predicted'
			] )
			->from( 'ores_classification' )
			->where( [ 'oresc_rev' => $revIds ] )
			->orderBy( 'oresc_probability' )
			->caller( __METHOD__ )
			->fetchResultSet();

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
		$dbw = $this->getDb();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'ores_classification' )
			->rows( [
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
			] )
			->caller( __METHOD__ )
			->execute();

		$user = self::getTestUser();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'recentchanges' )
			->row( [
				'rc_this_oldid' => '567',
				'rc_cur_id' => 3,
				'rc_last_oldid' => '425',
				'rc_comment_id' => 1,
				'rc_actor' => $user->getUser()->getActorId(),
				'rc_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'recentchanges' )
			->row( [
				'rc_this_oldid' => '12345',
				'rc_cur_id' => 3,
				'rc_last_oldid' => '567',
				'rc_comment_id' => 1,
				'rc_actor' => $user->getUser()->getActorId(),
				'rc_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->storage->storeScores( $scores, null, [ 'goodfaith' ] );

		$expected = [
			(object)[
				'oresc_rev' => '12345',
				'oresc_model' => (string)self::DAMAGING,
				'oresc_class' => '1',
				'oresc_probability' => '0.067',
				'oresc_is_predicted' => '0'
			],
			(object)[
				'oresc_rev' => '567',
				'oresc_model' => (string)self::DAMAGING,
				'oresc_class' => '1',
				'oresc_probability' => '0.600',
				'oresc_is_predicted' => '1'
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

		$res = $this->getDb()->newSelectQueryBuilder()
			->select( [
				'oresc_rev',
				'oresc_model',
				'oresc_class',
				'oresc_probability',
				'oresc_is_predicted'
				] )
			->from( 'ores_classification' )
			->orderBy( 'oresc_probability' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->assertEquals( $expected, iterator_to_array( $res, false ) );
	}

	public static function storeScoresInvalidProvider() {
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
		$excep = new RuntimeException( __METHOD__ );
		$this->expectExceptionObject( $excep );
		$this->storage->storeScores(
			$data,
			static function () use ( $excep ) {
				throw $excep;
			}
		);
	}

	public function testPurgeRows() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'ores_classification' )
			->rows( [
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
			] )
			->caller( __METHOD__ )
			->execute();

		$this->storage->purgeRows( [ 12344, 12346 ] );

		$res = $this->getDb()->newSelectQueryBuilder()
			->select( [
				'oresc_rev',
				'oresc_model',
				'oresc_class',
				'oresc_probability',
				'oresc_is_predicted'
			] )
			->from( 'ores_classification' )
			->caller( __METHOD__ )
			->fetchResultSet();

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
