<?php
namespace ORES\Tests;

use ORES;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks
 */
class OresHooksTest extends \MediaWikiTestCase {
	protected $user;

	protected function setUp() {
		parent::setUp();

		$this->user = static::getTestUser()->getUser();
		$this->user->setOption( 'ores-enabled', "1" );
		$this->user->setOption( 'oresDamagingPref', 'soft' );
		$this->user->saveSettings();
	}

	public function testOresEnabled() {
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );
		$this->assertArrayHasKey( 'ores-enabled', $prefs );

	}

	public function testOresPrefs() {
		$preferences = [];
		ORES\Hooks::onGetPreferences( $this->user, $preferences );
		$this->assertArrayHasKey( 'oresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'oresWatchlistHideNonDamaging', $preferences );
		$this->assertArrayHasKey( 'oresRCHideNonDamaging', $preferences );
	}

	public function testOresRCObj() {
		$row = new \stdClass();
		$row->ores_threshold = 0.2;
		$row->oresc_probability = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;

		$rc = \RecentChange::newFromRow( $row );
		$this->assertTrue( ORES\Hooks::getScoreRecentChangesList( $rc ) );

		$row->ores_threshold = 0.4;
		$rc = \RecentChange::newFromRow( $row );
		$this->assertFalse( ORES\Hooks::getScoreRecentChangesList( $rc ) );
	}
}


