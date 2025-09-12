<?php

namespace ORES\Tests\Hooks;

use MediaWiki\RecentChanges\RecentChange;
use ORES\Hooks\Hooks;
use ORES\Tests\MockOresServiceBuilder;

/**
 * @group ORES
 * @group Database
 * @covers \ORES\Hooks\RecentChangeSaveHookHandler
 */
class RecentChangeSaveHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$mockOresService = MockOresServiceBuilder::getORESServiceMock( $this );
		$this->setService( 'ORESService', $mockOresService );
		$this->overrideConfigValues( [
			'OresModels' => [
				'damaging' => [ 'enabled' => true, 'namespaces' => [ 0, 2 ], 'excludeBots' => true ],
				'goodfaith' => [ 'enabled' => false ],
				'articlequality' => [ 'enabled' => true, 'namespaces' => [ 0 ], 'excludeBots' => false ],
				'draftquality' => [ 'enabled' => true, 'sources' => [ RecentChange::SRC_NEW ] ],
			],
			'OresExcludeBots' => false,
		] );
	}

	public static function provideOnRecentChange_save() {
		return [
			[ 0, 0, RecentChange::SRC_EDIT, [ 'damaging', 'articlequality' ] ],
			[ 2, 0, RecentChange::SRC_NEW, [ 'damaging', 'draftquality' ] ],
			[ 0, 1, RecentChange::SRC_EDIT, [ 'articlequality' ] ],
			[ 2, 0, RecentChange::SRC_EDIT, [ 'damaging' ] ],
		];
	}

	/**
	 * @dataProvider provideOnRecentChange_save
	 */
	public function testOnRecentChange_save( $ns, $isBot, $source, $expectedModels ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'ORESFetchScoreJob' )->delete();
		$revId = mt_rand( 1000, 9999 );

		$rc = RecentChange::newFromRow( (object)[
			'rc_namespace' => $ns,
			'rc_title' => 'Test123',
			'rc_patrolled' => 0,
			'rc_timestamp' => '20150921134808',
			'rc_deleted' => 0,
			'rc_comment' => '',
			'rc_bot' => $isBot,
			'rc_comment_text' => '',
			'rc_comment_data' => null,
			'rc_source' => $source,
			'rc_this_oldid' => $revId,
			'rc_user' => 1,
			'rc_user_text' => 'Test user',
		] );
		( new Hooks )->onRecentChange_save( $rc );

		$actual = $jobQueueGroup->get( 'ORESFetchScoreJob' )->pop()->getParams();
		$actual['requestId'] = 'foo';

		$expected = [
			'revid' => $revId,
			'originalRequest' => [ 'ip' => '127.0.0.1', 'userAgent' => false ],
			'models' => $expectedModels,
			'precache' => true,
			'requestId' => 'foo',
			'title' => 'Test123',
			'namespace' => $ns,
		];
		$this->assertEquals( $expected, $actual );
	}

	public static function provideOnRecentChange_saveNotQueued() {
		return [
			[ 2, 1, RecentChange::SRC_EDIT ],
			[ 1, 0, RecentChange::SRC_EDIT ],
			[ 0, 0, RecentChange::SRC_LOG ],
			[ 0, 1, RecentChange::SRC_LOG ],
			[ 2, 0, RecentChange::SRC_LOG ],
		];
	}

	/**
	 * @dataProvider provideOnRecentChange_saveNotQueued
	 */
	public function testOnRecentChange_saveNotQueued( $ns, $isBot, $source ) {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'ORESFetchScoreJob' )->delete();
		$revId = mt_rand( 1000, 9999 );

		$rc = RecentChange::newFromRow( (object)[
			'rc_namespace' => $ns,
			'rc_title' => 'Test123',
			'rc_patrolled' => 0,
			'rc_timestamp' => '20150921134808',
			'rc_deleted' => 0,
			'rc_comment' => '',
			'rc_bot' => $isBot,
			'rc_comment_text' => '',
			'rc_comment_data' => null,
			'rc_source' => $source,
			'rc_this_oldid' => $revId,
			'rc_user' => 1,
			'rc_user_text' => 'Test user',
		] );
		( new Hooks )->onRecentChange_save( $rc );

		$this->assertFalse( $jobQueueGroup->get( 'ORESFetchScoreJob' )->pop() );
	}

	public function testOnRecentChange_saveHook() {
		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$jobQueueGroup->get( 'ORESFetchScoreJob' )->delete();
		$revId = mt_rand( 1000, 9999 );

		$rc = RecentChange::newFromRow( (object)[
			'rc_namespace' => 0,
			'rc_title' => 'Test123',
			'rc_patrolled' => 0,
			'rc_timestamp' => '20150921134808',
			'rc_deleted' => 0,
			'rc_comment' => '',
			'rc_bot' => 0,
			'rc_comment_text' => '',
			'rc_comment_data' => null,
			'rc_source' => RecentChange::SRC_EDIT,
			'rc_this_oldid' => $revId,
			'rc_user' => 1,
			'rc_user_text' => 'Test user',
		] );
		$this->setTemporaryHook( 'ORESCheckModels', static function ( $rc, &$models ) {
			$models = [ 'model_1' ];
		} );
		( new Hooks )->onRecentChange_save( $rc );

		$actual = $jobQueueGroup->get( 'ORESFetchScoreJob' )->pop()->getParams();
		$actual['requestId'] = 'foo';

		$expected = [
			'revid' => $revId,
			'originalRequest' => [ 'ip' => '127.0.0.1', 'userAgent' => false ],
			'models' => [ 'model_1' ],
			'precache' => true,
			'requestId' => 'foo',
			'title' => 'Test123',
			'namespace' => 0,
		];
		$this->assertEquals( $expected, $actual );
	}

}
