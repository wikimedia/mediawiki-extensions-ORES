<?php

namespace ORES\Tests\Hooks;

use JobQueueGroup;
use ORES\Hooks\RecentChangeSaveHookHandler;
use RecentChange;

/**
 * @group ORES
 * @covers ORES\Hooks\RecentChangeSaveHookHandler
 */
class RecentChangeSaveHookHandlerTest extends \MediaWikiTestCase {

	/**
	 * @covers ORES\Hooks\RecentChangeSaveHookHandler::onRecentChange_save
	 */
	public function testOnRecentChange_save() {
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
		] );
		RecentChangeSaveHookHandler::onRecentChange_save( $rc );

		$this->assertFalse( JobQueueGroup::singleton()->get( 'ORESFetchScoreJob' )->isEmpty() );
	}

}
