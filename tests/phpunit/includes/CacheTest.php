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
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.2',
				'oresm_is_current' => true
			],
			[
				'oresm_name' => 'reverted',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => true
			],
			[
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
						'prediction' => true,
						'probability' => [ 'true' => 0.9, 'false' => 0.1 ]
					],
				],
				[
					[
						'oresc_rev' => 1111,
						'oresc_model' => '1',
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
						'prediction' => true,
						'probability' => [ 'true' => 0.6, 'false' => 0.4 ]
					],
					'reverted' => [
						'prediction' => false,
						'probability' => [ 'true' => 0.3, 'false' => 0.7 ]
					]
				],
				[
					[
						'oresc_rev' => 12345,
						'oresc_model' => '1',
						'oresc_class' => 1,
						'oresc_probability' => 0.6,
						'oresc_is_predicted' => true
					],
					[
						'oresc_rev' => 12345,
						'oresc_model' => '2',
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
					12345 => [
						'damaging' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.6, 'false' => 0.4 ]
						],
						'reverted' => [
							'prediction' => false,
							'probability' => [ 'true' => 0.3, 'false' => 0.7 ]
						]
					]
				],
				[
					(object)[
						'oresc_rev' => '12345',
						'oresc_model' => '1',
						'oresc_class' => '1',
						'oresc_probability' => '0.600',
						'oresc_is_predicted' => '1'
					],
					(object)[
						'oresc_rev' => '12345',
						'oresc_model' => '2',
						'oresc_class' => '1',
						'oresc_probability' => '0.300',
						'oresc_is_predicted' => '0'
					],
				],
				[ 12345 ]
			],
			[
				[
					1111 => [
						'damaging' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.8, 'false' => 0.2 ]
						],
					],
					12345 => [
						'damaging' => [
							'prediction' => true,
							'probability' => [ 'true' => 0.6, 'false' => 0.4 ]
						],
						'reverted' => [
							'prediction' => false,
							'probability' => [ 'true' => 0.3, 'false' => 0.7 ]
						]
					]
				],
				[
					(object)[
						'oresc_rev' => '1111',
						'oresc_model' => '1',
						'oresc_class' => '1',
						'oresc_probability' => '0.800',
						'oresc_is_predicted' => '1'
					],
				],
				[ 1111 ]
			]
		];
	}

	/**
	 * @dataProvider storeScoresProvider
	 */
	public function testStoreScores( $scores, $expected, $revIds ) {
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
