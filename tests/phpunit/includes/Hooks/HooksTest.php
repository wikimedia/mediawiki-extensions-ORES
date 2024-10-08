<?php

namespace ORES\Tests;

use MediaWiki\Context\IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use ORES\Hooks\Hooks;
use ORES\Storage\HashModelLookup;
use ORES\Storage\ScoreStorage;

/**
 * @group ORES
 * @group Database
 * @covers \ORES\Hooks\Hooks
 */
class HooksTest extends \MediaWikiIntegrationTestCase {

	/** @var IContextSource */
	protected $context;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'OresFiltersThresholds' => [
				'damaging' => [
					'maybebad' => [ 'min' => 0.16, 'max' => 1 ],
					'likelybad' => [ 'min' => 0.56, 'max' => 1 ],
				]
			],
			'OresWikiId' => 'testwiki',
			'OresBaseUrl' => 'https://ores.example.test/',
		] );

		$user = $this->getTestUser()->getUser();
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, 'ores-enabled', 1 );
		$userOptionsManager->setOption( $user, 'oresDamagingPref', 'maybebad' );
		$userOptionsManager->setOption( $user, 'ores-damaging-flag-rc', 1 );
		$userOptionsManager->setOption( $user, 'oresHighlight', 1 );
		$userOptionsManager->setOption( $user, 'rcenhancedfilters-disable', 1 );
		$userOptionsManager->saveOptions( $user );
		$this->context = HelpersTest::getContext( $user );
	}

	/**
	 * @covers \ORES\Hooks\Hooks::onRecentChangesPurgeRows
	 */
	public function testOnRecentChangesPurgeRows() {
		$revIds = [ 1, 5, 8, 13 ];
		$rows = array_map( static function ( $id ) {
			return (object)[ 'rc_this_oldid' => $id ];
		}, $revIds );

		$mock = $this->createMock( ScoreStorage::class );
		$mock->expects( $this->once() )
			->method( 'purgeRows' )
			->with( $revIds );

		$this->setService( 'ORESScoreStorage', $mock );

		( new Hooks )->onRecentChangesPurgeRows( $rows );
	}

	/**
	 * @covers \ORES\Hooks\Hooks::onBeforePageDisplay
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

		( new Hooks )->onBeforePageDisplay( $outputPage, $skin );

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
