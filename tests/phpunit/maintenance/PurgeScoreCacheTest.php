<?php

namespace ORES\Tests\Maintenance;

use MediaWiki\MediaWikiServices;

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
class PurgeScoreCacheTest extends \MediaWikiTestCase {

	public function setUp() {
		parent::setUp();
		$this->tablesUsed = [
			'ores_classification',
			'ores_model',
		];

		$this->maintenance = new PurgeScoreCache();

		TestHelper::clearOresTables();

		// Reset service to purge cached models.
		MediaWikiServices::getInstance()->resetServiceForTesting( 'ORESModelLookup' );
	}

	public function testPurgeScoreCache_noop() {
		// FIXME: Shouldn't be necessary once we capture output.
		$this->maintenance->loadWithArgv( [ '--quiet' ] );

		$this->maintenance->execute();

		// Well, this is dirty but the point I want to demonstrate is that
		// the previous function didn't crash.
		$this->assertTrue( true );
	}

	public function testPurgeScoreCache_bad_model() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertModelData();
		TestHelper::insertOresData( $revId, [
			'damaging' => 0.1,
		] );

		$this->maintenance->loadWithArgv( [ '--quiet', '--model', 'not_a_thing' ] );

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

	public function testPurgeScoreCache_all() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertModelData();
		TestHelper::insertOresData( $revId, [
			TestHelper::DAMAGING_OLD => 0.2,
			'damaging' => 0.1,
		] );

		$this->maintenance->loadWithArgv( [ '--quiet', '--all' ] );

		$this->maintenance->execute();

		$remainingScores = \wfGetDB( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[ 'oresc_rev' => $revId ],
			__METHOD__
		);

		$this->assertEquals( [], iterator_to_array( $remainingScores, false ) );
	}

	public function testPurgeScoreCache_oldModels() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertModelData();
		TestHelper::insertOresData( $revId, [
			TestHelper::DAMAGING_OLD => 0.2,
			'damaging' => 0.1,
		] );

		$this->maintenance->loadWithArgv( [ '--quiet' ] );

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

	public function testPurgeScoreCache_nonRecent() {
		$this->tablesUsed[] = 'recentchanges';

		$revId = mt_rand( 1000, 9999 );
		$revIdOld = $revId - 1;

		TestHelper::insertModelData();
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

		$this->maintenance->loadWithArgv( [ '--quiet', '--old' ] );

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
		TestHelper::insertModelData();
		TestHelper::insertOresData( $revId, [
			'damaging' => 0.1,
			'reverted' => 0.3,
		] );

		$this->maintenance->loadWithArgv( [ '--quiet', '--model', 'reverted', '--all' ] );

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
