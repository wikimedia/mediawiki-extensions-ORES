<?php

namespace ORES\Tests;

use IContextSource;
use ORES\Hooks\Helpers;
use ORES\Storage\HashModelLookup;
use ORES\Storage\ThresholdLookup;
use RequestContext;
use SpecialPage;
use Title;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks\Helpers
 */
class HelpersTest extends \MediaWikiIntegrationTestCase {

	protected $user;

	protected $context;

	protected function setUp(): void {
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
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'ores-enabled', 1 );
		$userOptionsManager->setOption( $this->user, 'oresDamagingPref', 'maybebad' );
		$userOptionsManager->setOption( $this->user, 'oresHighlight', 1 );
		$userOptionsManager->setOption( $this->user, 'ores-damaging-flag-rc', 1 );
		$userOptionsManager->saveOptions( $this->user );

		$this->context = self::getContext( $this->user );
	}

	/**
	 * @covers ORES\Hooks\Helpers::getDamagingLevelPreference
	 */
	public function testGetDamagingLevelPreference_Watchlist() {
		$level =
			Helpers::getDamagingLevelPreference( $this->user,
				Title::newFromText( 'Watchlist', NS_SPECIAL ) );

		$this->assertEquals( 'maybebad', $level );
	}

	/**
	 * @covers ORES\Hooks\Helpers::getThreshold
	 */
	public function testGetThreshold_null() {
		$mock = $this->createMock( ThresholdLookup::class );
		$mock->method( 'getThresholds' )
			->willReturn( [] );

		$this->setService( 'ORESThresholdLookup', $mock );
		$threshold = Helpers::getThreshold( 'damaging', $this->user );

		$this->assertNull( $threshold );
	}

	/**
	 * @covers ORES\Hooks\Helpers::getThreshold
	 */
	public function testGetThreshold_invalid() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( "Unknown ORES test: 'not_a_thing'" );
		Helpers::getThreshold( 'not_a_thing', $this->user );
	}

	/**
	 * @covers ORES\Hooks\Helpers::getThreshold
	 */
	public function testGetThreshold() {
		$modelData = [ 'damaging' => [ 'id' => 5, 'version' => '0.0.2' ] ];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );

		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();

		$userOptionsManager->setOption( $this->user, 'rcOresDamagingPref', 'maybebad' );
		$this->assertSame(
			0.16, Helpers::getThreshold( 'damaging', $this->user, $this->context->getTitle() )
		);

		// b/c
		$userOptionsManager->setOption( $this->user, 'rcOresDamagingPref', 'soft' );
		$this->assertSame(
			0.56, Helpers::getThreshold( 'damaging', $this->user, $this->context->getTitle() )
		);
	}

	/**
	 * @param User $user
	 *
	 * @return IContextSource
	 */
	public static function getContext( User $user ) {
		$context = new RequestContext();

		$context->setLanguage( 'en' );
		$context->setUser( $user );
		$context->setTitle( SpecialPage::getTitleFor( 'Recentchanges' ) );

		return $context;
	}

	/**
	 * @covers ORES\Hooks\Helpers::joinWithOresTables
	 */
	public function testJoinWithOresTables() {
		$modelData = [ 'damaging' => [ 'id' => 5, 'version' => '0.0.2' ] ];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );

		$tables = [];
		$fields = [];
		$join_conds = [];
		Helpers::joinWithOresTables( 'damaging', 'rc_this_oldid', $tables, $fields, $join_conds );

		$this->assertEquals( [
			'ores_damaging_cls' => 'ores_classification',
		], $tables );
		$this->assertEquals( [
			'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
		], $fields );
		$this->assertEquals( [
			'ores_damaging_cls' => [ 'LEFT JOIN', [
				'ores_damaging_cls.oresc_model' => 5,
				'ores_damaging_cls.oresc_rev=rc_this_oldid',
				'ores_damaging_cls.oresc_class' => 1,
			], ],
		], $join_conds );
	}

	/**
	 * @covers ORES\Hooks\Helpers::hideNonDamagingFilter
	 */
	public function testHideNonDamagingFilter() {
		$modelData = [ 'damaging' => [ 'id' => 5, 'version' => '0.0.2' ] ];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );

		$fields = [];
		$conds = [];
		Helpers::hideNonDamagingFilter( $fields, $conds, true, $this->user );

		$this->assertEquals( [
			'ores_damaging_threshold' => 0.16,
		], $fields );
		$this->assertEquals( [
			'ores_damaging_cls.oresc_probability > \'0.16\'',
		], $conds );
	}

}
