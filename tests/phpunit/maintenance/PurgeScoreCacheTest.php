<?php

namespace ORES\Tests\Maintenance;

use MediaWiki\MediaWikiServices;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use ORES\Maintenance\PurgeScoreCache;
use ORES\Storage\SqlModelLookup;
use ORES\Tests\TestHelper;

/**
 * @group ORES
 * @group Database
 * @covers \ORES\Maintenance\PurgeScoreCache
 */
class PurgeScoreCacheTest extends MaintenanceBaseTestCase {

	public function getMaintenanceClass() {
		return PurgeScoreCache::class;
	}

	protected function setUp(): void {
		parent::setUp();

		TestHelper::insertModelData();

		// Reset service to purge cached models.
		$this->setService(
			'ORESModelLookup',
			new SqlModelLookup( MediaWikiServices::getInstance()->getConnectionProvider() )
		);
	}

	public function testPurgeScoreCache_emptyDb() {
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

		$remainingScores = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ] )
			->from( 'ores_classification' )
			->where( [ 'oresc_rev' => $revId ] )
			->caller( __METHOD__ )->fetchResultSet();

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

		$remainingScores = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ] )
			->from( 'ores_classification' )
			->where( [ 'oresc_rev' => $revId ] )
			->caller( __METHOD__ )->fetchResultSet();

		$this->assertEquals( [], iterator_to_array( $remainingScores, false ) );

		$pattern = '/purging scores from all model versions from \'damaging\'.+'
			. 'skipping \'reverted\'/s';
		$this->expectOutputRegex( $pattern );
	}

	public function testPurgeScoreCache_oldModels() {
		$revId = mt_rand( 1000, 9999 );
		TestHelper::insertOresData( $revId, [
			TestHelper::DAMAGING_OLD => 0.2,
			'damaging' => 0.1,
		] );

		$this->maintenance->execute();

		$remainingScores = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ] )
			->from( 'ores_classification' )
			->where( [ 'oresc_rev' => $revId ] )
			->caller( __METHOD__ )->fetchResultSet();

		$this->assertEquals( [ (object)[
			'oresc_rev' => (string)$revId,
			'oresc_class' => '1',
			'oresc_probability' => '0.100',
			'oresc_model' => (string)TestHelper::DAMAGING,
		] ], iterator_to_array( $remainingScores, false ) );

		$this->expectOutputRegex( '/purging scores from old model versions/' );
	}

	public function testPurgeScoreCache_nonRecent() {
		$testUser = $this->getTestUser()->getUser();
		$userData = [
			'rc_actor' => $testUser->getActorId(),
		];

		$revId = mt_rand( 1000, 9999 );
		$revIdOld = $revId - 1;

		TestHelper::insertOresData( $revId, [
			'damaging' => 0.1,
		] );
		TestHelper::insertOresData( $revIdOld, [
			'damaging' => 0.2,
		] );

		$dbw = $this->getDb();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'recentchanges' )
			->row(
				[
					'rc_this_oldid' => $revId,
					'rc_comment_id' => 1,
					'rc_timestamp' => $dbw->timestamp(),
				] + $userData )
			->caller( __METHOD__ )
			->execute();

		$this->maintenance->loadWithArgv( [ '--old' ] );

		$this->maintenance->execute();

		$remainingScores = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ] )
			->from( 'ores_classification' )
			->where( [ 'oresc_rev' => $revId ] )
			->caller( __METHOD__ )->fetchResultSet();

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

		$remainingScores = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ] )
			->from( 'ores_classification' )
			->where( [ 'oresc_rev' => $revId ] )
			->caller( __METHOD__ )->fetchResultSet();

		$this->assertEquals( [ (object)[
			'oresc_rev' => (string)$revId,
			'oresc_class' => '1',
			'oresc_probability' => '0.100',
			'oresc_model' => (string)TestHelper::DAMAGING,
		] ], iterator_to_array( $remainingScores, false ) );
	}

}
