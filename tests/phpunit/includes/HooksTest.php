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
use SpecialContributions;

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
			'hidenondamaging' => [ 'msg' => 'ores-damaging-filter', 'default' => false ]
		];
		$this->assertSame( $expected, $filters );
	}

	public function testOnChangesListSpecialPageQuery() {
		$this->setMwGlobals( 'wgUser', $this->user );

		$opts = new FormOptions();

		$opts->add( 'hidenondamaging', true, 2 );

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
				'rc_patrolled' => 0
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

		ORES\Hooks::OnEnhancedChangesListModifyLineData( $ecl, $data, $block, $rc, $classes );

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

		ORES\Hooks::OnEnhancedChangesListModifyLineData( $ecl, $data, $block, $rc, $classes );

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
			'join_conds' => []
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
	 * @return IContextSource
	 */
	private static function getContext( $user ) {

		$context = new RequestContext();

		$context->setLanguage( 'en' );
		$context->setUser( $user );

		return $context;
	}
}
