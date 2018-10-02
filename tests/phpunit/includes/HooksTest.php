<?php

namespace ORES\Tests;

use MediaWiki\MediaWikiServices;
use ORES\Hooks;
use ORES\Storage\HashModelLookup;
use ORES\Storage\ScoreStorage;
use OutputPage;

/**
 * @group ORES
 * @covers ORES\Hooks
 */
class HooksTest extends \MediaWikiTestCase {

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

		$user = static::getTestUser()->getUser();
		$user->setOption( 'ores-enabled', 1 );
		$user->setOption( 'oresDamagingPref', 'maybebad' );
		$user->setOption( 'ores-damaging-flag-rc', 1 );
		$user->setOption( 'oresHighlight', 1 );
		$user->setOption( 'rcenhancedfilters-disable', 1 );
		$user->saveSettings();
		$this->context = HelpersTest::getContext( $user );
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

		$skin = MediaWikiServices::getInstance()->getSkinFactory()->makeSkin( 'fallback' );
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

}
