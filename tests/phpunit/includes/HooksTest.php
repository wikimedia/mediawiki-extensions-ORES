<?php

namespace ORES\Tests;

use ChangesList;
use ChangesListSpecialPage;
use ContribsPager;
use EnhancedChangesList;
use FormOptions;
use IContextSource;
use ORES;
use RCCacheEntry;
use RecentChange;
use RequestContext;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks
 */
class OresHooksTest extends \MediaWikiTestCase {

	protected $user;

	protected $context;

	protected function setUp() {
		parent::setUp();

		$this->user = static::getTestUser()->getUser();
		$this->user->setOption( 'ores-enabled', "1" );
		$this->user->setOption( 'oresDamagingPref', 'soft' );
		$this->user->saveSettings();

		$this->context = self::getContext( $this->user );
	}

	public function testOresPrefs() {
		$preferences = [];
		ORES\Hooks::onGetPreferences( $this->user, $preferences );
		$this->assertArrayHasKey( 'oresDamagingPref', $preferences );
		$this->assertArrayHasKey( 'oresWatchlistHideNonDamaging', $preferences );
		$this->assertArrayHasKey( 'oresRCHideNonDamaging', $preferences );
	}

	public function testOresRCObj() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;

		$rc = RecentChange::newFromRow( $row );
		$this->assertTrue( ORES\Hooks::getScoreRecentChangesList( $rc, $this->context ) );

		$row->ores_damaging_threshold = 0.4;
		$rc = RecentChange::newFromRow( $row );
		$this->assertFalse( ORES\Hooks::getScoreRecentChangesList( $rc, $this->context ) );
	}

	public function testOnChangesListSpecialPageFilters() {
		$filters = [];
		$clsp = $this->getMock( ChangesListSpecialPage::class );

		$clsp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$clsp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		ORES\Hooks::onChangesListSpecialPageFilters( $clsp, $filters );
		$expected = [
			'hidenondamaging' => [ 'msg' => 'ores-damaging-filter', 'default' => false ],
			'damaging' => [ 'msg' => false, 'default' => 'all' ],
			'goodfaith' => [ 'msg' => false, 'default' => 'all' ],
		];
		$this->assertSame( $expected, $filters );
	}

	public function testOnChangesListSpecialPageQuery_hidenondamaging() {
		$this->setMwGlobals( [
			'wgUser' => $this->user,
			'wgOresModels' => [
				'damaging' => true,
				'goodfaith' => false,
			]
		] );

		$opts = new FormOptions();

		$opts->add( 'hidenondamaging', true, 2 );
		$opts->add( 'damaging', 'all' );
		$opts->add( 'goodfaith', 'all' );

		$tables = [];
		$fields = [];
		$conds = [];
		$query_options = [];
		$join_conds = [];
		ORES\Hooks::onChangesListSpecialPageQuery(
			'',
			$tables,
			$fields,
			$conds,
			$query_options,
			$join_conds,
			$opts
		);
		$expected = [
			'tables' => [
				'ores_damaging_mdl' => 'ores_model',
				'ores_damaging_cls' => 'ores_classification'
			],
			'fields' => [
				'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
				'ores_damaging_threshold' => "'0.7'"
			],
			'conds' => [
				"ores_damaging_cls.oresc_probability > '0.7'",
				'rc_patrolled' => 0,
			],
			'query_options' => [ 'STRAIGHT_JOIN' ],
			'join_conds' => [
				'ores_damaging_mdl' => [ 'INNER JOIN',
					[
						'ores_damaging_mdl.oresm_is_current' => 1,
						'ores_damaging_mdl.oresm_name' => 'damaging'
					]
				],
				'ores_damaging_cls' => [ 'INNER JOIN',
					[
						'ores_damaging_cls.oresc_model = ores_damaging_mdl.oresm_id',
						'rc_this_oldid = ores_damaging_cls.oresc_rev',
						'ores_damaging_cls.oresc_class' => 1
					]
				]
			],
		];
		$this->assertSame( $expected['tables'], $tables );
		$this->assertSame( $expected['fields'], $fields );
		$this->assertSame( $expected['conds'], $conds );
		$this->assertSame( $expected['query_options'], $query_options );
		$this->assertSame( $expected['join_conds'], $join_conds );
	}

	public function onChangesListSpecialPageQuery_goodfaith_provider() {
		return [
			[ 'good', 0.35, 1 ],
			[ 'maybebad', 0, 0.65 ],
			[ 'bad', 0, 0.15 ],
			[ 'good,maybebad', 0, 1 ],
			[ 'maybebad,bad', 0, 0.65 ],
			[ 'good,maybebad,bad', 0, 1 ],
		];
	}

	/**
	 * @dataProvider onChangesListSpecialPageQuery_goodfaith_provider
	 */
	public function testOnChangesListSpecialPageQuery_goodfaith(
		$goodfaithValue,
		$expectedMin,
		$expectedMax
	) {
		$this->setMwGlobals( [
			'wgUser' => $this->user,
			'wgOresModels' => [
				'damaging' => false,
				'goodfaith' => true,
			]
		] );

		$opts = new FormOptions();

		$opts->add( 'hidenondamaging', false );
		$opts->add( 'goodfaith', $goodfaithValue );

		$tables = [];
		$fields = [];
		$conds = [];
		$query_options = [];
		$join_conds = [];
		ORES\Hooks::onChangesListSpecialPageQuery(
			'',
			$tables,
			$fields,
			$conds,
			$query_options,
			$join_conds,
			$opts
		);
		$expected = [
			'tables' => [
				'ores_goodfaith_mdl' => 'ores_model',
				'ores_goodfaith_cls' => 'ores_classification'
			],
			'fields' => [
				'ores_goodfaith_score' => 'ores_goodfaith_cls.oresc_probability',
			],
			'conds' => [
				"(ores_goodfaith_cls.oresc_probability BETWEEN $expectedMin AND $expectedMax)"
			],
			'join_conds' => [
				'ores_goodfaith_mdl' => [ 'INNER JOIN',
					[
						'ores_goodfaith_mdl.oresm_is_current' => 1,
						'ores_goodfaith_mdl.oresm_name' => 'goodfaith'
					]
				],
				'ores_goodfaith_cls' => [ 'INNER JOIN',
					[
						'ores_goodfaith_cls.oresc_model = ores_goodfaith_mdl.oresm_id',
						'rc_this_oldid = ores_goodfaith_cls.oresc_rev',
						'ores_goodfaith_cls.oresc_class' => 1
					]
				]
			],
		];
		$this->assertSame( $expected['tables'], $tables );
		$this->assertSame( $expected['fields'], $fields );
		$this->assertSame( $expected['conds'], $conds );
		$this->assertSame( $expected['join_conds'], $join_conds );
	}

	public function testOnChangesListSpecialPageQuery_damaging() {
		$this->setMwGlobals( [
			'wgUser' => $this->user,
			'wgOresModels' => [
				'damaging' => true,
				'goodfaith' => false,
			]
		] );

		$opts = new FormOptions();

		$opts->add( 'hidenondamaging', false );
		$opts->add( 'damaging', 'maybebad' );

		$tables = [];
		$fields = [];
		$conds = [];
		$query_options = [];
		$join_conds = [];
		ORES\Hooks::onChangesListSpecialPageQuery(
			'',
			$tables,
			$fields,
			$conds,
			$query_options,
			$join_conds,
			$opts
		);
		$expected = [
			'tables' => [
				'ores_damaging_mdl' => 'ores_model',
				'ores_damaging_cls' => 'ores_classification',
			],
			'fields' => [
				'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
			],
			'conds' => [
				'(ores_damaging_cls.oresc_probability BETWEEN 0.16 AND 1)',
			],
			'join_conds' => [
				'ores_damaging_mdl' => [ 'INNER JOIN',
					[
						'ores_damaging_mdl.oresm_is_current' => 1,
						'ores_damaging_mdl.oresm_name' => 'damaging',
					]
				],
				'ores_damaging_cls' => [ 'INNER JOIN',
					[
						'ores_damaging_cls.oresc_model = ores_damaging_mdl.oresm_id',
						'rc_this_oldid = ores_damaging_cls.oresc_rev',
						'ores_damaging_cls.oresc_class' => 1,
					]
				]
			],
		];
		$this->assertSame( $expected['tables'], $tables );
		$this->assertSame( $expected['fields'], $fields );
		$this->assertSame( $expected['conds'], $conds );
		$this->assertSame( $expected['join_conds'], $join_conds );
	}

	public function testOnChangesListSpecialPageQuery_goodfaith_goodbad() {
		$this->setMwGlobals( [
			'wgUser' => $this->user,
			'wgOresModels' => [
				'damaging' => false,
				'goodfaith' => true,
			]
		] );

		$opts = new FormOptions();

		$opts->add( 'hidenondamaging', false );
		$opts->add( 'goodfaith', 'good,bad' );

		$tables = [];
		$fields = [];
		$conds = [];
		$query_options = [];
		$join_conds = [];
		ORES\Hooks::onChangesListSpecialPageQuery(
			'',
			$tables,
			$fields,
			$conds,
			$query_options,
			$join_conds,
			$opts
		);
		$expected = [
			'tables' => [
				'ores_goodfaith_mdl' => 'ores_model',
				'ores_goodfaith_cls' => 'ores_classification'
			],
			'fields' => [
				'ores_goodfaith_score' => 'ores_goodfaith_cls.oresc_probability',
			],
			'conds' => [
				"(ores_goodfaith_cls.oresc_probability BETWEEN 0.35 AND 1) OR " .
				"(ores_goodfaith_cls.oresc_probability BETWEEN 0 AND 0.15)",
			],
			'join_conds' => [
				'ores_goodfaith_mdl' => [ 'INNER JOIN',
					[
						'ores_goodfaith_mdl.oresm_is_current' => 1,
						'ores_goodfaith_mdl.oresm_name' => 'goodfaith'
					]
				],
				'ores_goodfaith_cls' => [ 'INNER JOIN',
					[
						'ores_goodfaith_cls.oresc_model = ores_goodfaith_mdl.oresm_id',
						'rc_this_oldid = ores_goodfaith_cls.oresc_rev',
						'ores_goodfaith_cls.oresc_class' => 1
					]
				]
			],
		];
		$this->assertSame( $expected['tables'], $tables );
		$this->assertSame( $expected['fields'], $fields );
		$this->assertSame( $expected['conds'], $conds );
		$this->assertSame( $expected['join_conds'], $join_conds );
	}

	public function testOnEnhancedChangesListModifyLineDataDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;
		$rc = RecentChange::newFromRow( $row );
		$rc = RCCacheEntry::newFromParent( $rc );

		$ecl = $this->getMockBuilder( EnhancedChangesList::class )
			->disableOriginalConstructor()
			->getMock();

		$ecl->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$ecl->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$data = [];
		$block = [];
		$classes = [];

		ORES\Hooks::onEnhancedChangesListModifyLineData( $ecl, $data, $block, $rc, $classes );

		$this->assertSame( [ 'recentChangesFlags' => [ 'damaging' => true ] ], $data );
		$this->assertSame( [], $block );
		$this->assertSame( [ 'damaging' ], $classes );
	}

	public function testOnEnhancedChangesListModifyLineDataNonDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.4;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;
		$rc = RecentChange::newFromRow( $row );
		$rc = RCCacheEntry::newFromParent( $rc );

		$ecl = $this->getMockBuilder( EnhancedChangesList::class )
			->disableOriginalConstructor()
			->getMock();

		$ecl->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$ecl->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$data = [];
		$block = [];
		$classes = [];

		ORES\Hooks::onEnhancedChangesListModifyLineData( $ecl, $data, $block, $rc, $classes );

		$this->assertSame( [], $data );
		$this->assertSame( [], $block );
		$this->assertSame( [], $classes );
	}

	public function testOnOldChangesListModifyLineDataDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;
		$rc = RecentChange::newFromRow( $row );
		$rc = RCCacheEntry::newFromParent( $rc );

		$cl = $this->getMockBuilder( ChangesList::class )
			->disableOriginalConstructor()
			->getMock();

		$cl->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cl->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$classes = [];

		$s = ' <span class="mw-changeslist-separator">. .</span> ';
		ORES\Hooks::onOldChangesListRecentChangesLine( $cl, $s, $rc, $classes );

		$this->assertSame(
			' <span class="mw-changeslist-separator">. .</span>' .
			' <abbr class="ores-damaging" title="This edit needs review">r</abbr>',
			$s
		);
		$this->assertSame( [ 'damaging' ], $classes );
	}

	public function testOnOldChangesListModifyLineDataNonDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.4;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;
		$rc = RecentChange::newFromRow( $row );
		$rc = RCCacheEntry::newFromParent( $rc );

		$cl = $this->getMockBuilder( ChangesList::class )
			->disableOriginalConstructor()
			->getMock();

		$cl->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cl->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$classes = [];

		$s = ' <span class="mw-changeslist-separator">. .</span> ';
		ORES\Hooks::onOldChangesListRecentChangesLine( $cl, $s, $rc, $classes );

		$this->assertSame( ' <span class="mw-changeslist-separator">. .</span> ', $s );
		$this->assertSame( [], $classes );
	}

	public function testOnContribsGetQueryInfo() {
		$cp = $this->getMockBuilder( ContribsPager::class )
			->disableOriginalConstructor()
			->getMock();

		$cp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$query = [
			'tables' => [],
			'fields' => [],
			'conds' => [],
			'options' => [],
			'join_conds' => [],
		];
		ORES\Hooks::onContribsGetQueryInfo(
			$cp,
			$query
		);
		$expected = [
			'tables' => [
				'ores_damaging_mdl' => 'ores_model',
				'ores_damaging_cls' => 'ores_classification'
			],
			'fields' => [
				'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
				'ores_damaging_threshold' => "'0.7'"
			],
			'conds' => [],
			'join_conds' => [
				'ores_damaging_mdl' => [ 'LEFT JOIN',
				                         [
					                         'ores_damaging_mdl.oresm_is_current' => 1,
					                         'ores_damaging_mdl.oresm_name' => 'damaging'
				                         ]
				],
				'ores_damaging_cls' => [ 'LEFT JOIN',
				                         [
					                         'ores_damaging_cls.oresc_model = ores_damaging_mdl.oresm_id',
					                         'rev_id = ores_damaging_cls.oresc_rev',
					                         'ores_damaging_cls.oresc_class' => 1
				                         ]
				]
			],
		];
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

		ORES\Hooks::onSpecialContributionsFormatRowFlags( $this->context, $row, $flags );

		$this->assertSame(
			[ '<abbr class="ores-damaging" title="This edit needs review">r</abbr>' ],
			$flags
		);
	}

	public function testOnSpecialContributionsFormatRowFlagsNonDamaging() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.4;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$flags = [];

		ORES\Hooks::onSpecialContributionsFormatRowFlags( $this->context, $row, $flags );

		$this->assertSame( [], $flags );
	}

	public function testOnContributionsLineEndingDamaging() {
		$cp = $this->getMockBuilder( ContribsPager::class )
			->disableOriginalConstructor()
			->getMock();

		$cp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$ret = [];
		$classes = [];

		ORES\Hooks::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [ 'damaging' ], $classes );
		$this->assertSame( [], $ret );
	}

	public function testOnContributionsLineEndingNonDamaging() {
		$cp = $this->getMockBuilder( ContribsPager::class )
			->disableOriginalConstructor()
			->getMock();

		$cp->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cp->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.4;
		$row->ores_damaging_score = 0.3;
		$row->rev_id = 0;

		$ret = [];
		$classes = [];

		ORES\Hooks::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [], $classes );
		$this->assertSame( [], $ret );
	}

	public function testOnGetPreferencesEnabled() {
		$prefs = [];
		ORES\Hooks::onGetPreferences( $this->user, $prefs );

		$this->assertSame( 3, count( $prefs ) );
	}

	public function testOnGetPreferencesDisabled() {
		$user = static::getTestUser()->getUser();
		$user->setOption( 'ores-enabled', "0" );
		$user->saveSettings();

		$prefs = [];
		ORES\Hooks::onGetPreferences( $this->user, $prefs );

		$this->assertSame( [], $prefs );
	}

	public function testOnGetBetaFeaturePreferences() {
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

		$this->assertSame( 1, count( $prefs ) );
		$this->assertArrayHasKey( 'ores-enabled', $prefs );
	}

	public function testOresEnabled() {
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );
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

		return $context;
	}

}
