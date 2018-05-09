<?php

namespace ORES\Tests\Maintenance;

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use ORES\Maintenance\PopulateDatabase;

use ORES\Tests\MockOresServiceBuilder;
use ORES\Tests\TestHelper;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Maintenance\PopulateDatabase
 */
class PopulateDatabaseTest extends MaintenanceBaseTestCase {

	public function getMaintenanceClass() {
		return PopulateDatabase::class;
	}

	public function setUp() {
		parent::setUp();
		$this->tablesUsed = [
			'ores_classification',
			'ores_model',
			'recentchanges',
		];

		TestHelper::clearOresTables();
		TestHelper::insertModelData();
		\wfGetDB( DB_MASTER )->delete( 'recentchanges', '*', __METHOD__ );

		$this->setMwGlobals( [
			'wgOresModels' => [ 'damaging' => [ 'enabled' => true ] ],
			'wgOresWikiId' => 'wiki',
			'wgOresExcludeBots' => true,
		] );
		$mockOresService = MockOresServiceBuilder::getORESServiceMock( $this );
		$this->setService( 'ORESService', $mockOresService );
	}

	public function provideTestData() {
		return [
			// Populate a single score.
			[
				[
					(object)[
						'oresc_rev' => '123',
						'oresc_class' => '1',
						'oresc_probability' => '0.32',
						'oresc_model' => (string)TestHelper::DAMAGING,
					]
				],
				[
					[ 'rc_this_oldid' => '123' ]
				],
				[],
				[],
			],

			// Populate two scores.
			[
				[
					(object)[
						'oresc_rev' => '123',
						'oresc_class' => '1',
						'oresc_probability' => '0.32',
						'oresc_model' => (string)TestHelper::DAMAGING,
					], (object)[
						'oresc_rev' => '321',
						'oresc_class' => '1',
						'oresc_probability' => '0.12',
						'oresc_model' => (string)TestHelper::DAMAGING,
					]
				],
				[
					[ 'rc_this_oldid' => '123' ],
					[ 'rc_this_oldid' => '321' ],
				],
				[
					123 => [ 'damaging' => 0.32 ],
				],
				[],
			],

			// Only one of two scores will be populated, due to a limit.
			[
				[
					(object)[
						'oresc_rev' => '321',
						'oresc_class' => '1',
						'oresc_probability' => '0.12',
						'oresc_model' => (string)TestHelper::DAMAGING,
					]
				],
				[
					[ 'rc_this_oldid' => '123' ],
					[ 'rc_this_oldid' => '321' ],
				],
				[],
				[
					'--batch', '1',
					'--number', '1',
				],
			],

			// A bot edit will be excluded.
			[
				[
					(object)[
						'oresc_rev' => '123',
						'oresc_class' => '1',
						'oresc_probability' => '0.32',
						'oresc_model' => (string)TestHelper::DAMAGING,
					]
				],
				[
					[ 'rc_this_oldid' => '123', 'rc_bot' => '0' ],
					[ 'rc_this_oldid' => '321', 'rc_bot' => '1' ],
				],
				[],
				[],
			],

			// Scores will be populated in two batches.
			// TODO: Can we prove that batching happened?
			[
				[
					(object)[
						'oresc_rev' => '123',
						'oresc_class' => '1',
						'oresc_probability' => '0.32',
						'oresc_model' => (string)TestHelper::DAMAGING,
					],
					(object)[
						'oresc_rev' => '321',
						'oresc_class' => '1',
						'oresc_probability' => '0.12',
						'oresc_model' => (string)TestHelper::DAMAGING,
					]
				],
				[
					[ 'rc_this_oldid' => '123' ],
					[ 'rc_this_oldid' => '321' ],
				],
				[],
				[
					'--batch', '1',
				],
			],
		];
	}

	/**
	 * @dataProvider provideTestData
	 */
	public function testPopulateDatabase( $expected, $rcContents, $oresContents, $argv ) {
		global $wgActorTableSchemaMigrationStage;

		$testUser = $this->getTestUser()->getUser();
		$userData = [];
		if ( $wgActorTableSchemaMigrationStage > MIGRATION_OLD ) {
			$userData += [
				'rc_actor' => $testUser->getActorId(),
			];
		}
		if ( $wgActorTableSchemaMigrationStage < MIGRATION_NEW ) {
			$userData += [
				'rc_user' => $testUser->getId(),
				'rc_user_text' => $testUser->getName(),
			];
		}
		foreach ( $rcContents as &$row ) {
			$row += $userData;
		}

		\wfGetDB( DB_MASTER )->insert( 'recentchanges', $rcContents, __METHOD__ );

		foreach ( $oresContents as $revId => $scores ) {
			TestHelper::insertOresData( $revId, $scores );
		}

		$this->maintenance->loadWithArgv( $argv );
		$this->maintenance->execute();

		$scores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			null,
			__METHOD__,
			[ 'ORDER BY' => 'oresc_rev' ]
		);

		$this->assertEquals( $expected, iterator_to_array( $scores, false ) );
	}

}
