<?php

namespace ORES\Tests;

use IContextSource;
use ORES\Hooks;
use ORES\Hooks\PreferencesHookHandler;
use RequestContext;
use SpecialPage;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks
 */
class HooksTest extends \MediaWikiTestCase {

	protected $user;

	protected $context;

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresFiltersThresholds' => [
				'damaging' => [
					'maybebad' => [ 'min' => 0.16, 'max' => 1 ],
					'likelybad' => [ 'min' => 0.56, 'max' => 1 ],
				]
			],
			'wgOresWikiId' => 'testwiki',
		] );

		$this->user = static::getTestUser()->getUser();
		$this->user->setOption( 'ores-enabled', 1 );
		$this->user->setOption( 'oresDamagingPref', 'maybebad' );
		$this->user->setOption( 'oresHighlight', 1 );
		$this->user->setOption( 'ores-damaging-flag-rc', 1 );
		$this->user->saveSettings();

		$this->context = self::getContext( $this->user );
	}

	public function testOresPrefs() {
		$preferences = [];
		PreferencesHookHandler::onGetPreferences( $this->user, $preferences );
		$this->assertArrayHasKey( 'oresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'rcOresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'oresWatchlistHideNonDamaging', $preferences );
		$this->assertArrayHasKey( 'oresRCHideNonDamaging', $preferences );
	}

	public function testGetThreshold() {
		$this->user->setOption( 'rcOresDamagingPref', 'maybebad' );
		$this->assertEquals(
			0.16,
			Hooks::getThreshold( 'damaging', $this->user, $this->context->getTitle() )
		);

		// b/c
		$this->user->setOption( 'rcOresDamagingPref', 'soft' );
		$this->assertEquals(
			0.56,
			Hooks::getThreshold( 'damaging', $this->user, $this->context->getTitle() )
		);
	}

	public function testOnGetPreferencesEnabled() {
		$prefs = [];
		PreferencesHookHandler::onGetPreferences( $this->user, $prefs );

		$this->assertSame( 6, count( $prefs ) );
	}

	public function testOnGetBetaFeaturePreferences_on() {
		$this->setMwGlobals( 'wgOresExtensionStatus', 'on' );
		$prefs = [];
		Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

		$this->assertSame( 0, count( $prefs ) );
	}

	public function testOnGetBetaFeaturePreferences_off() {
		$this->setMwGlobals( 'wgOresExtensionStatus', 'off' );
		$prefs = [];
		Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

		$this->assertSame( 0, count( $prefs ) );
	}

	public function testOnGetBetaFeaturePreferences_beta() {
		$this->setMwGlobals( 'wgOresExtensionStatus', 'beta' );
		$prefs = [];
		Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

		$this->assertSame( 1, count( $prefs ) );
		$this->assertArrayHasKey( 'ores-enabled', $prefs );
	}

	/**
	 * @param User $user
	 *
	 * @return IContextSource
	 */
	private static function getContext( User $user ) {
		$context = new RequestContext();

		$context->setLanguage( 'en' );
		$context->setUser( $user );
		$context->setTitle( SpecialPage::getTitleFor( 'Recentchanges' ) );

		return $context;
	}

}
