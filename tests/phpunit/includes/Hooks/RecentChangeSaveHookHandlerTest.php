<?php

namespace ORES\Tests\Hooks;

use JobQueueGroup;
use ORES\Hooks\RecentChangeSaveHookHandler;
use ORES\Tests\MockOresServiceBuilder;
use RecentChange;

/**
 * @group ORES
 * @covers ORES\Hooks\RecentChangeSaveHookHandler
 */
class RecentChangeSaveHookHandlerTest extends \MediaWikiTestCase {

	public function setUp() {
		parent::setUp();
		$mockOresService = MockOresServiceBuilder::getORESServiceMock( $this );
		$this->setService( 'ORESService', $mockOresService );
		$this->setMwGlobals( [
			'wgOresModels' => [
				'damaging' => [ 'enabled' => true, 'namespaces' => [ 0, 2 ], 'excludeBots' => true ],
				'goodfaith' => [ 'enabled' => false ],
				'wp10' => [ 'enabled' => true, 'namespaces' => [ 0 ], 'excludeBots' => false ],
				'draftquality' => [ 'enabled' => true, 'types' => [ RC_NEW ] ],
			],
			'wgOresExcludeBots' => false,
		] );
	}

	/**
	 * @covers ORES\Hooks\RecentChangeSaveHookHandler::onRecentChange_save
	 */
	public function testOnRecentChange_saveOld() {
		$this->setMwGlobals( [
			'wgOresModels' => [ 'damaging' => true ],
		] );
		JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->delete();

		$rc = RecentChange::newFromRow( (object)[
			'rc_namespace' => NS_MAIN,
			'rc_title' => 'Test123',
			'rc_patrolled' => 0,
			'rc_timestamp' => '20150921134808',
			'rc_deleted' => 0,
			'rc_comment' => '',
			'rc_comment_text' => '',
			'rc_comment_data' => null,
			'rc_type' => RC_EDIT,
			'rc_this_oldid' => mt_rand( 1000, 9999 ),
			'rc_user' => 1,
			'rc_user_text' => 'Test user',
		] );
		RecentChangeSaveHookHandler::onRecentChange_save( $rc );

		$this->assertFalse( JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->isEmpty() );
	}

	public function provieOnRecentChange_save() {
		return [
			[ 0, 0, RC_EDIT, [ 'damaging', 'wp10' ] ],
			[ 2, 0, RC_NEW, [ 'damaging', 'draftquality' ] ],
			[ 0, 1, RC_EDIT, [ 'wp10' ] ],
			[ 2, 0, RC_EDIT, [ 'damaging' ] ],
		];
	}

	/**
	 * @covers ORES\Hooks\RecentChangeSaveHookHandler::onRecentChange_save
	 * @dataProvider provieOnRecentChange_save
	 */
	public function testOnRecentChange_saveNew( $ns, $isBot, $type, $expectedModels ) {
		JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->delete();
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
			'rc_type' => $type,
			'rc_this_oldid' => $revId,
			'rc_user' => 1,
			'rc_user_text' => 'Test user',
		] );
		RecentChangeSaveHookHandler::onRecentChange_save( $rc );

		$actual = JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->pop()->getParams();
		$actual['requestId'] = 'foo';

		$expected = [
			'revid' => $revId,
			'originalRequest' => [ 'ip' => '127.0.0.1', 'userAgent' => false ],
			'models' => $expectedModels,
			'precache' => true,
			'requestId' => 'foo'
		];
		$this->assertSame( $expected, $actual );
	}

	public function provieOnRecentChange_saveNotQueued() {
		return [
			[ 2, 1, RC_EDIT ],
			[ 1, 0, RC_EDIT ],
			[ 0, 0, RC_LOG ],
			[ 0, 1, RC_LOG ],
			[ 2, 0, RC_LOG ],
		];
	}

	/**
	 * @covers ORES\Hooks\RecentChangeSaveHookHandler::onRecentChange_save
	 * @dataProvider provieOnRecentChange_saveNotQueued
	 */
	public function testOnRecentChange_saveNewNotQueued( $ns, $isBot, $type ) {
		JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->delete();
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
			'rc_type' => $type,
			'rc_this_oldid' => $revId,
			'rc_user' => 1,
			'rc_user_text' => 'Test user',
		] );
		RecentChangeSaveHookHandler::onRecentChange_save( $rc );

		$this->assertFalse( JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->pop() );
	}

}