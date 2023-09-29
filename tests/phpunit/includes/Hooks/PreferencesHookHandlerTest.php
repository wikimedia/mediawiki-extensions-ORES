<?php

namespace ORES\Tests\Hooks;

use ORES\Hooks\PreferencesHookHandler;

/**
 * @group ORES
 * @group Database
 * @coversDefaultClass \ORES\Hooks\PreferencesHookHandler
 */
class PreferencesHookHandlerTest extends \MediaWikiIntegrationTestCase {

	protected $user;

	protected function setUp(): void {
		parent::setUp();

		$this->user = $this->getTestUser()->getUser();
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'ores-enabled', 1 );
		$userOptionsManager->setOption( $this->user, 'oresDamagingPref', 'maybebad' );
		$userOptionsManager->setOption( $this->user, 'oresHighlight', 1 );
		$userOptionsManager->setOption( $this->user, 'ores-damaging-flag-rc', 1 );
		$userOptionsManager->saveOptions( $this->user );
	}

	/**
	 * @covers ::onGetPreferences
	 */
	public function testOresPrefs() {
		$preferences = [];
		( new PreferencesHookHandler )->onGetPreferences( $this->user, $preferences );
		$this->assertArrayHasKey( 'oresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'rcOresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'oresWatchlistHideNonDamaging', $preferences );
		$this->assertArrayHasKey( 'oresRCHideNonDamaging', $preferences );
	}

	/**
	 * @covers ::onGetPreferences
	 */
	public function testOnGetPreferencesEnabled() {
		$prefs = [];
		( new PreferencesHookHandler )->onGetPreferences( $this->user, $prefs );

		$this->assertCount( 6, $prefs );
	}

}
