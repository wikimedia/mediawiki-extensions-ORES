<?php

namespace ORES\Tests;

use MediaWikiLangTestCase;
use ORES;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Cache
 */
class OresCacheTest extends MediaWikiLangTestCase {

	const DAMAGING_OLD = 1;
	const REVERTED = 2;
	const DAMAGING = 3;
	/**
	 * @var ORES\Cache
	 */
	protected $cache;

	protected function setUp() {
		parent::setUp();

		$this->tablesUsed[] = 'ores_classification';
		$this->tablesUsed[] = 'ores_model';

		self::insertModelData();

		$this->cache = ORES\Cache::instance();
	}

	public static function insertModelData() {
		$db = \wfGetDB( DB_MASTER );
		$dump = [
			[
				'oresm_id' => self::DAMAGING,
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.2',
				'oresm_is_current' => true
			],
			[
				'oresm_id' => self::REVERTED,
				'oresm_name' => 'reverted',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => true
			],
			[
				'oresm_id' => self::DAMAGING_OLD,
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => false
			],
		];

		$db->delete( 'ores_model', '*' );

		foreach ( $dump as $row ) {
			$db->insert( 'ores_model', $row );
		}
	}

	public function testFilterScores() {
		$data = [
			12 => [ '...' ],
			34 => [ '...' ],
			56 => [ '...' ],
			78 => [ '...' ],
			90 => [ '...' ],
		];
		$whitelist = [ 34, 56, 11, 90 ];
		$expectedData = [
			34 => [ '...' ],
			56 => [ '...' ],
			90 => [ '...' ],
		];

		$this->assertSame( $expectedData, $this->cache->filterScores( $data, $whitelist ) );
	}

	public function testGetModels() {
		$models = $this->cache->getModels();
		// TODO: Fix duplicate entries
		$this->assertSame( [ 'damaging', 'damaging', 'reverted' ], $models );
	}

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
						'oresc_model' => (string)self::DAMAGING,
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
						'oresc_model' => (string)self::DAMAGING,
						'oresc_class' => 1,
						'oresc_probability' => 0.6,
						'oresc_is_predicted' => true
					],
					[
						'oresc_rev' => 12345,
						'oresc_model' => (string)self::REVERTED,
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
		$data = [];
		$this->cache->processRevision( $data, $revId, $revisionData );

		$this->assertSame( $expected, $data );
	}

	public function storeScoresProvider() {
		return [
			[
				[
					'wiki' => [
						'models' => [
							'damaging' => [
								'version' => '0.3.0',
							],
							'goodfaith' => [
								'version' => '0.3.0',
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

		// Nasty that we do this processing here (and nastier that we were
		// previously doing it in Cache).
		$scores = $scores['wiki']['scores'];

		$this->cache->storeScores( $scores );

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

}
