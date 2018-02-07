<?php

namespace ORES\Tests;

use ORES\Hooks;
use ORES\Hooks\PreferencesHookHandler;
use ORES\Storage\HashModelLookup;
use ORES\Storage\ScoreStorage;
use OutputPage;
use SkinFactory;

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

		$this->context = HelpersTest::getContext( $this->user );
	}

	/**
	 * @covers ORES\Hooks::onRecentChangesPurgeRows
	 */
	public function testOnRecentChangesPurgeRows() {
		$revIds = [ 1, 5, 8, 13 ];
		$rows = array_map( function ( $id ) {
			return (object)[ 'rc_this_oldid' => $id ];
		}, $revIds );

		$mock = $this->createMock( ScoreStorage::class );
		$mock->expects( $this->once() )
			->method( 'purgeRows' )
			->with( $this->equalTo( $revIds ) );

		$this->setService( 'ORESScoreStorage', $mock );

		Hooks::onRecentChangesPurgeRows( $rows );
	}

	/**
	 * @covers ORES\Hooks::onBeforePageDisplay
	 */
	public function testOnBeforePageDisplay() {
		$modelData = [ 'damaging' => [ 'id' => 5, 'version' => '0.0.2' ] ];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );

		$oresData = [
			123 => [
				'damaging' => 0.4,
			]
		];
		$thresholds = [
			'damaging' => [
				'maybebad' => 0.16,
				'likelybad' => 0.56,
			],
		];

		$skin = SkinFactory::getDefaultInstance()->makeSkin( 'fallback' );
		$outputPage = new OutputPage( $this->context );
		$outputPage->setProperty( 'oresData', $oresData );

		Hooks::onBeforePageDisplay( $outputPage, $skin );

		$vars = $outputPage->getJsConfigVars();
		$this->assertEquals( [
			'oresData' => $oresData,
			'oresThresholds' => $thresholds,
		], $vars );
		$styles = $outputPage->getModuleStyles();
		$this->assertEquals( [
			'ext.ores.styles',
		], $styles );
		$modules = $outputPage->getModules();
		$this->assertEquals( [
			'ext.ores.highlighter',
		], $modules );
	}

	/**
	 * @covers ORES\Hooks\PreferencesHookHandler::onGetPreferences
	 * @todo Move to a dedicated file
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
	 * @todo Move to a dedicated file
	 */
	public function testOnGetPreferencesEnabled() {
		$prefs = [];
		PreferencesHookHandler::onGetPreferences( $this->user, $prefs );

		$this->assertSame( 6, count( $prefs ) );
	}

}
