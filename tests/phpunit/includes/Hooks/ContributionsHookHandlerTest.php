<?php

namespace ORES\Tests;

use ContribsPager;
use IContextSource;
use ORES\Hooks\ContributionsHooksHandler;
use ORES\Storage\HashModelLookup;
use RequestContext;
use SpecialPage;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks\ContributionsHooksHandler
 */
class ContributionsHookHandlerTest extends \MediaWikiIntegrationTestCase {

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

		$modelData = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 7, 'version' => '0.0.3' ],
		];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );

		$this->user = static::getTestUser()->getUser();
		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userOptionsManager->setOption( $this->user, 'ores-enabled', 1 );
		$userOptionsManager->setOption( $this->user, 'rcOresDamagingPref', 'maybebad' );
		$userOptionsManager->setOption( $this->user, 'oresHighlight', 1 );
		$userOptionsManager->setOption( $this->user, 'ores-damaging-flag-rc', 1 );
		$userOptionsManager->setOption( $this->user, 'rcenhancedfilters-disable', true );
		$userOptionsManager->saveOptions( $this->user );

		$this->context = self::getContext( $this->user );
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

	public function provideOnContribsGetQueryInfo() {
		$expected = [
			'tables' => [
				'ores_damaging_cls' => 'ores_classification'
			],
			'fields' => [
				'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
				'ores_damaging_threshold' => 0.16,
			],
			'conds' => [],
			'join_conds' => [
				'ores_damaging_cls' => [
					'LEFT JOIN',
					[
						'ores_damaging_cls.oresc_model' => 5,
						'ores_damaging_cls.oresc_rev=rev_id',
						'ores_damaging_cls.oresc_class' => 1
					]
				]
			],
		];

		$expectedDamaging = $expected;
		$expectedDamaging['conds'] = [ 'ores_damaging_cls.oresc_probability > \'0.16\'' ];

		return [
			'all' => [ $expected, false ],
			'damaging only' => [ $expectedDamaging, true ]
		];
	}

	/**
	 * @dataProvider provideOnContribsGetQueryInfo
	 * @covers ORES\Hooks\ContributionsHooksHandler::onContribsGetQueryInfo
	 */
	public function testOnContribsGetQueryInfo( array $expected, $nonDamaging ) {
		$cp =
			$this->createMock( ContribsPager::class );

		$cp->method( 'getUser' )
			->willReturn( $this->user );

		$cp->method( 'getTitle' )
			->willReturn( SpecialPage::getTitleFor( 'Contributions' ) );

		$cp->method( 'getContext' )
			->willReturn( $this->context );

		if ( $nonDamaging === true ) {
			$this->context->getRequest()->setVal( 'hidenondamaging', true );
		}

		$query = [
			'tables' => [],
			'fields' => [],
			'conds' => [],
			'options' => [],
			'join_conds' => [],
		];
		ContributionsHooksHandler::onContribsGetQueryInfo( $cp, $query );

		$this->assertSame( $expected['tables'], $query['tables'] );
		$this->assertSame( $expected['fields'], $query['fields'] );
		$this->assertSame( $expected['conds'], $query['conds'] );
		$this->assertSame( $expected['join_conds'], $query['join_conds'] );
	}

	/**
	 * @covers ORES\Hooks\ContributionsHooksHandler::onSpecialContributionsFormatRowFlags
	 */
	public function testOnSpecialContributionsFormatRowFlagsDamaging() {
		$row = (object)[
			'ores_damaging_threshold' => 0.2,
			'ores_damaging_score' => 0.3,
			'rev_id' => 0,
		];

		$flags = [];

		ContributionsHooksHandler::onSpecialContributionsFormatRowFlags( $this->context, $row, $flags );

		$this->assertSame( [ '<abbr class="ores-damaging" title="This edit needs review">r</abbr>' ],
			$flags );
	}

	/**
	 * @covers ORES\Hooks\ContributionsHooksHandler::onSpecialContributionsFormatRowFlags
	 */
	public function testOnSpecialContributionsFormatRowFlagsNonDamaging() {
		$row = (object)[
			'ores_damaging_threshold' => 0.4,
			'ores_damaging_score' => 0.3,
			'rev_id' => 0,
		];

		$flags = [];

		ContributionsHooksHandler::onSpecialContributionsFormatRowFlags( $this->context, $row, $flags );

		$this->assertSame( [], $flags );
	}

	/**
	 * @covers ORES\Hooks\ContributionsHooksHandler::onContributionsLineEnding
	 */
	public function testOnContributionsLineEndingDamaging() {
		$cp =
			$this->createMock( ContribsPager::class );

		$cp->method( 'getUser' )
			->willReturn( $this->user );

		$cp->method( 'getTitle' )
			->willReturn( SpecialPage::getTitleFor( 'Contributions' ) );

		$cp->method( 'getContext' )
			->willReturn( $this->context );

		$row = (object)[
			'ores_damaging_threshold' => 0.2,
			'ores_damaging_score' => 0.3,
			'rev_id' => 0,
		];

		$ret = [];
		$classes = [];

		ContributionsHooksHandler::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [ 'ores-highlight', 'damaging' ], $classes );
		$this->assertSame( [], $ret );
	}

	/**
	 * @covers ORES\Hooks\ContributionsHooksHandler::onContributionsLineEnding
	 */
	public function testOnContributionsLineEndingNonDamaging() {
		$cp =
			$this->createMock( ContribsPager::class );

		$cp->method( 'getUser' )
			->willReturn( $this->user );

		$cp->method( 'getTitle' )
			->willReturn( SpecialPage::getTitleFor( 'Contributions' ) );

		$cp->method( 'getContext' )
			->willReturn( $this->context );

		$row = (object)[
			'ores_damaging_threshold' => 0.4,
			'ores_damaging_score' => 0.3,
			'rev_id' => 0,
		];

		$ret = [];
		$classes = [];

		ContributionsHooksHandler::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [], $classes );
		$this->assertSame( [], $ret );
	}

}
