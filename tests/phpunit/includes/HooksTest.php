<?php
namespace ORES\Tests;

use ChangesListSpecialPage;
use RecentChange;
use ORES;

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
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;

		$rc = RecentChange::newFromRow( $row );
		$this->assertTrue( ORES\Hooks::getScoreRecentChangesList( $rc ) );

		$row->ores_damaging_threshold = 0.4;
		$rc = RecentChange::newFromRow( $row );
		$this->assertFalse( ORES\Hooks::getScoreRecentChangesList( $rc ) );
	}

	public function testOnChangesListSpecialPageFilters() {
		$filters = [];
		$clsp = $this->getMock( ChangesListSpecialPage::class );

		$clsp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		ORES\Hooks::onChangesListSpecialPageFilters( $clsp, $filters );
		$expected = [
			'hidenondamaging' => [ 'msg' => 'ores-damaging-filter', 'default' => false ]
		];
		$this->assertSame( $expected, $filters );
	}
}
