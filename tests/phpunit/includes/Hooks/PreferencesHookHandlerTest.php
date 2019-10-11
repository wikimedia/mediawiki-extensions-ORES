<?php

namespace ORES\Tests\Hooks;

use ORES\Hooks\PreferencesHookHandler;

/**
 * @group ORES
 * @covers ORES\Hooks\PreferencesHookHandler
 */
class PreferencesHookHandlerTest extends \MediaWikiTestCase {

	protected $user;

	protected function setUp() : void {
		parent::setUp();

		$this->user = static::getTestUser()->getUser();
		$this->user->setOption( 'ores-enabled', 1 );
		$this->user->setOption( 'oresDamagingPref', 'maybebad' );
		$this->user->setOption( 'oresHighlight', 1 );
		$this->user->setOption( 'ores-damaging-flag-rc', 1 );
		$this->user->saveSettings();
	}

	/**
	 * @covers ORES\Hooks\PreferencesHookHandler::onGetPreferences
	 */
	public function testOresPrefs() {
		$preferences = [];
		PreferencesHookHandler::onGetPreferences( $this->user, $preferences );
		$this->assertArrayHasKey( 'oresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'rcOresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'oresWatchlistHideNonDamaging', $preferences );
		$this->assertArrayHasKey( 'oresRCHideNonDamaging', $preferences );
	}

	/**
	 * @covers ORES\Hooks\PreferencesHookHandler::onGetPreferences
	 */
	public function testOnGetPreferencesEnabled() {
		$prefs = [];
		PreferencesHookHandler::onGetPreferences( $this->user, $prefs );

		$this->assertSame( 6, count( $prefs ) );
	}

}
