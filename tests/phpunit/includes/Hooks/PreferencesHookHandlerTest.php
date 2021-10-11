<?php

namespace ORES\Tests\Hooks;

use ORES\Hooks\PreferencesHookHandler;

/**
 * @group ORES
 * @covers ORES\Hooks\PreferencesHookHandler
 */
class PreferencesHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = static::getTestUser()->getUser();
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'ores-enabled', 1 );
		$userOptionsManager->setOption( $this->user, 'oresDamagingPref', 'maybebad' );
		$userOptionsManager->setOption( $this->user, 'oresHighlight', 1 );
		$userOptionsManager->setOption( $this->user, 'ores-damaging-flag-rc', 1 );
		$userOptionsManager->saveOptions( $this->user );
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

		$this->assertCount( 6, $prefs );
	}

}
