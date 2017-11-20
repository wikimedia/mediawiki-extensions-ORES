<?php

namespace ORES\Tests;

use ContribsPager;
use IContextSource;
use ORES\Hooks\ContributionsHooksHandler;
use RequestContext;
use SpecialPage;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks\ContributionsHooksHandler
 */
class ContributionsHookHandlerTest extends \MediaWikiTestCase {

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
				'ores_damaging_mdl' => 'ores_model',
				'ores_damaging_cls' => 'ores_classification'
			],
			'fields' => [
				'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
				'ores_damaging_threshold' => "'0.16'"
			],
			'conds' => [],
			'join_conds' => [
				'ores_damaging_mdl' => [
					'LEFT JOIN',
					[
						'ores_damaging_mdl.oresm_is_current' => 1,
						'ores_damaging_mdl.oresm_name' => 'damaging'
					]
				],
				'ores_damaging_cls' => [
					'LEFT JOIN',
					[
						'ores_damaging_cls.oresc_model = ores_damaging_mdl.oresm_id',
						'rev_id = ores_damaging_cls.oresc_rev',
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
	 */
	public function testOnContribsGetQueryInfo( array $expected, $nonDamaging ) {
		$cp =
			$this->getMockBuilder( ContribsPager::class )->disableOriginalConstructor()->getMock();

		$cp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cp->expects( $this->any() )
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Contributions' ) ) );

		$cp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

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

	public function testOnSpecialContributionsFormatRowFlagsDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$flags = [];

		ContributionsHooksHandler::onSpecialContributionsFormatRowFlags( $this->context, $row, $flags );

		$this->assertSame( [ '<abbr class="ores-damaging" title="This edit needs review">r</abbr>' ],
			$flags );
	}

	public function testOnSpecialContributionsFormatRowFlagsNonDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.4;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$flags = [];

		ContributionsHooksHandler::onSpecialContributionsFormatRowFlags( $this->context, $row, $flags );

		$this->assertSame( [], $flags );
	}

	public function testOnContributionsLineEndingDamaging() {
		$cp =
			$this->getMockBuilder( ContribsPager::class )->disableOriginalConstructor()->getMock();

		$cp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cp->expects( $this->any() )
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Contributions' ) ) );

		$cp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$ret = [];
		$classes = [];

		ContributionsHooksHandler::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [ 'ores-highlight', 'damaging' ], $classes );
		$this->assertSame( [], $ret );
	}

	public function testOnContributionsLineEndingNonDamaging() {
		$cp =
			$this->getMockBuilder( ContribsPager::class )->disableOriginalConstructor()->getMock();

		$cp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cp->expects( $this->any() )
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Contributions' ) ) );

		$cp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.4;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$ret = [];
		$classes = [];

		ContributionsHooksHandler::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [], $classes );
		$this->assertSame( [], $ret );
	}

}
