<?php

namespace ORES\Tests\Maintenance;

use MediaWiki\MediaWikiServices;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

use ORES\Maintenance\PurgeScoreCache;

use ORES\Tests\TestHelper;

/*
 * TODO: It would be ideal to extend a core class like \MaintenanceTest to take
 * care of capturing output and so on, but this doesn't seem to exist yet?
 * See https://phabricator.wikimedia.org/T184775
 *
 * This ficitious test class would have to be autoloadable, otherwise:
 *
 * require_once getenv( 'MW_INSTALL_PATH' ) !== false
 *     ? getenv( 'MW_INSTALL_PATH' ) . '/tests/phpunit/maintenance/MaintenanceTest.php'
 *     : __DIR__ . '/../../../../../tests/phpunit/maintenance/MaintenanceTest.php';
 */

/**
 * @group ORES
 * @group Database
 * @covers ORES\Maintenance\PurgeScoreCache
 */
class PurgeScoreCacheTest extends MaintenanceBaseTestCase {

	public function getMaintenanceClass() {
		return PurgeScoreCache::class;
	}

	public function setUp() {
		parent::setUp();
		$this->tablesUsed = [
			'ores_classification',
			'ores_model',
		];

		TestHelper::clearOresTables();
		TestHelper::insertModelData();

		// Reset service to purge cached models.
		MediaWikiServices::getInstance()->resetServiceForTesting( 'ORESModelLookup' );
	}

	public function testPurgeScoreCache_emptyDb() {
		TestHelper::clearOresTables();

		$this->maintenance->execute();

		// Well, this is dirty but the point I want to demonstrate is that
		// the previous function didn't crash.
		$this->assertTrue( true );
	}

	public function testPurgeScoreCache_bad_model() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertOresData( $revId, [
			'damaging' => 0.1,
		] );

		$this->maintenance->loadWithArgv( [ '--model', 'not_a_thing' ] );

		$this->maintenance->execute();

		$remainingScores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[ 'oresc_rev' => $revId ],
			__METHOD__
		);

		$this->assertEquals( [ (object)[
			'oresc_rev' => (string)$revId,
			'oresc_class' => '1',
			'oresc_probability' => '0.100',
			'oresc_model' => (string)TestHelper::DAMAGING,
		] ], iterator_to_array( $remainingScores, false ) );

		$this->expectOutputRegex( '/skipping \'not_a_thing\' model/' );
	}

	public function testPurgeScoreCache_all() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertOresData( $revId, [
			TestHelper::DAMAGING_OLD => 0.2,
			'damaging' => 0.1,
		] );

		$this->maintenance->loadWithArgv( [ '--all' ] );

		$this->maintenance->execute();

		$remainingScores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[ 'oresc_rev' => $revId ],
			__METHOD__
		);

		$this->assertEquals( [], iterator_to_array( $remainingScores, false ) );

		$pattern = '/skipping \'reverted\'.+'
			. 'purging scores from all model versions from \'damaging\'/s';
		$this->expectOutputRegex( $pattern );
	}

	public function testPurgeScoreCache_oldModels() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertOresData( $revId, [
			TestHelper::DAMAGING_OLD => 0.2,
			'damaging' => 0.1,
		] );

		$this->maintenance->execute();

		$remainingScores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[ 'oresc_rev' => $revId ],
			__METHOD__
		);

		$this->assertEquals( [ (object)[
			'oresc_rev' => (string)$revId,
			'oresc_class' => '1',
			'oresc_probability' => '0.100',
			'oresc_model' => (string)TestHelper::DAMAGING,
		] ], iterator_to_array( $remainingScores, false ) );

		$this->expectOutputRegex( '/purging scores from old model versions/' );
	}

	public function testPurgeScoreCache_nonRecent() {
		$this->tablesUsed[] = 'recentchanges';

		$revId = mt_rand( 1000, 9999 );
		$revIdOld = $revId - 1;

		TestHelper::insertOresData( $revId, [
			'damaging' => 0.1,
		] );
		TestHelper::insertOresData( $revIdOld, [
			'damaging' => 0.2,
		] );
		\wfGetDB( DB_MASTER )->insert( 'recentchanges', [
			'rc_this_oldid' => $revId,
			'rc_user_text' => 'TestUser',
		], __METHOD__ );

		$this->maintenance->loadWithArgv( [ '--old' ] );

		$this->maintenance->execute();

		$remainingScores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[ 'oresc_rev' => $revId ],
			__METHOD__
		);

		$this->assertEquals( [ (object)[
			'oresc_rev' => (string)$revId,
			'oresc_class' => '1',
			'oresc_probability' => '0.100',
			'oresc_model' => (string)TestHelper::DAMAGING,
		] ], iterator_to_array( $remainingScores, false ) );
	}

	public function testPurgeScoreCache_oneModel() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertOresData( $revId, [
			'damaging' => 0.1,
			'reverted' => 0.3,
		] );

		$this->maintenance->loadWithArgv( [ '--model', 'reverted', '--all' ] );

		$this->maintenance->execute();

		$remainingScores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[ 'oresc_rev' => $revId ],
			__METHOD__
		);

		$this->assertEquals( [ (object)[
			'oresc_rev' => (string)$revId,
			'oresc_class' => '1',
			'oresc_probability' => '0.100',
			'oresc_model' => (string)TestHelper::DAMAGING,
		] ], iterator_to_array( $remainingScores, false ) );
	}

}
