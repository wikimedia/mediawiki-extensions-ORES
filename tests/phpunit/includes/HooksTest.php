<?php

namespace ORES\Tests;

use ChangesList;
use ContribsPager;
use EnhancedChangesList;
use EventRelayerNull;
use FormOptions;
use HashBagOStuff;
use IContextSource;
use MediaWiki\MediaWikiServices;
use ORES;
use ORES\Hooks\PreferencesHookHandler;
use RCCacheEntry;
use RecentChange;
use RequestContext;
use SpecialPage;
use User;
use WANObjectCache;

/**
 * @group ORES
 * @covers ORES\Hooks
 */
class OresHooksTest extends \MediaWikiTestCase {

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
		$this->assertArrayHasKey( 'oresWatchlistHideNonDamaging', $preferences );
		$this->assertArrayHasKey( 'oresRCHideNonDamaging', $preferences );
	}

	public function testGetThreshold() {
		$this->user->setOption( 'oresDamagingPref', 'maybebad' );
		$this->assertEquals(
			0.16,
			ORES\Hooks::getThreshold( 'damaging', $this->user )
		);

		// b/c
		$this->user->setOption( 'oresDamagingPref', 'soft' );
		$this->assertEquals(
			0.56,
			ORES\Hooks::getThreshold( 'damaging', $this->user )
		);
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

	/**
	 * @dataProvider onChangesListSpecialPageQuery_provider
	 */
	public function testOnChangesListSpecialPageQuery( $modelConfig, $expectedQuery ) {
		$this->setMwGlobals( [
			'wgUser' => $this->user,
			'wgOresModels' => $modelConfig
		] );
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
			new FormOptions()
		);
		$this->assertSame( $expectedQuery['tables'], $tables );
		$this->assertSame( $expectedQuery['fields'], $fields );
		$this->assertSame( $expectedQuery['join_conds'], $join_conds );
	}

	public function onChangesListSpecialPageQuery_provider() {
		return [
			[
				[ 'damaging' => false, 'goodfaith' => false ],
				[
					'tables' => [],
					'fields' => [],
					'join_conds' => []
				]
			],
			[
				[ 'damaging' => true, 'goodfaith' => false ],
				[
					'tables' => [
						'ores_damaging_mdl' => 'ores_model',
						'ores_damaging_cls' => 'ores_classification'
					],
					'fields' => [
						'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
					],
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
								'rc_this_oldid = ores_damaging_cls.oresc_rev',
								'ores_damaging_cls.oresc_class' => 1
							]
						]
					]
				]
			],
			[
				[ 'damaging' => false, 'goodfaith' => true ],
				[
					'tables' => [
						'ores_goodfaith_mdl' => 'ores_model',
						'ores_goodfaith_cls' => 'ores_classification'
					],
					'fields' => [
						'ores_goodfaith_score' => 'ores_goodfaith_cls.oresc_probability',
					],
					'join_conds' => [
						'ores_goodfaith_mdl' => [ 'LEFT JOIN',
							[
								'ores_goodfaith_mdl.oresm_is_current' => 1,
								'ores_goodfaith_mdl.oresm_name' => 'goodfaith'
							]
						],
						'ores_goodfaith_cls' => [ 'LEFT JOIN',
							[
								'ores_goodfaith_cls.oresc_model = ores_goodfaith_mdl.oresm_id',
								'rc_this_oldid = ores_goodfaith_cls.oresc_rev',
								'ores_goodfaith_cls.oresc_class' => 1
							]
						]
					]
				]
			],
			[
				[ 'damaging' => true, 'goodfaith' => true ],
				[
					'tables' => [
						'ores_damaging_mdl' => 'ores_model',
						'ores_damaging_cls' => 'ores_classification',
						'ores_goodfaith_mdl' => 'ores_model',
						'ores_goodfaith_cls' => 'ores_classification'
					],
					'fields' => [
						'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
						'ores_goodfaith_score' => 'ores_goodfaith_cls.oresc_probability',
					],
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
								'rc_this_oldid = ores_damaging_cls.oresc_rev',
								'ores_damaging_cls.oresc_class' => 1
							]
						],
						'ores_goodfaith_mdl' => [ 'LEFT JOIN',
							[
								'ores_goodfaith_mdl.oresm_is_current' => 1,
								'ores_goodfaith_mdl.oresm_name' => 'goodfaith'
							]
						],
						'ores_goodfaith_cls' => [ 'LEFT JOIN',
							[
								'ores_goodfaith_cls.oresc_model = ores_goodfaith_mdl.oresm_id',
								'rc_this_oldid = ores_goodfaith_cls.oresc_rev',
								'ores_goodfaith_cls.oresc_class' => 1
							]
						]
					]
				]
			]
		];
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
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Recentchanges' ) ) );

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
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Recentchanges' ) ) );

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
		$this->assertSame( [ 'ores-highlight', 'damaging' ], $classes );
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
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Recentchanges' ) ) );

		$cl->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$classes = [];

		$s = ' <span class="mw-changeslist-separator">. .</span> ';
		ORES\Hooks::onOldChangesListRecentChangesLine( $cl, $s, $rc, $classes );

		$this->assertSame( ' <span class="mw-changeslist-separator">. .</span> ', $s );
		$this->assertSame( [], $classes );
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
		$cp = $this->getMockBuilder( ContribsPager::class )
			->disableOriginalConstructor()
			->getMock();

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
		ORES\Hooks::onContribsGetQueryInfo(
			$cp,
			$query
		);

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

		ORES\Hooks::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [ 'ores-highlight', 'damaging' ], $classes );
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

		ORES\Hooks::onContributionsLineEnding( $cp, $ret, $row, $classes );

		$this->assertSame( [], $classes );
		$this->assertSame( [], $ret );
	}

	public function testOnGetPreferencesEnabled() {
		$prefs = [];
		PreferencesHookHandler::onGetPreferences( $this->user, $prefs );

		$this->assertSame( 5, count( $prefs ) );
	}

	public function testOnGetBetaFeaturePreferences_on() {
		$this->setMwGlobals( 'wgOresExtensionStatus', 'on' );
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

		$this->assertSame( 0, count( $prefs ) );
	}

	public function testOnGetBetaFeaturePreferences_off() {
		$this->setMwGlobals( 'wgOresExtensionStatus', 'off' );
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

		$this->assertSame( 0, count( $prefs ) );
	}

	public function testOnGetBetaFeaturePreferences_beta() {
		$this->setMwGlobals( 'wgOresExtensionStatus', 'beta' );
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );

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

	private function mockStatsInCache() {
		$cache = new WANObjectCache( [
			'cache' => new HashBagOStuff(),
			'pool' => 'testcache-hash',
			'relayer' => new EventRelayerNull( [] )
		] );

		$invalidStats = [ 'will trigger the use of' => 'default values' ];

		$cache->set(
			$cache->makeKey( 'ORES', 'test_stats', 'damaging' ),
			$invalidStats,
			\WANObjectCache::TTL_DAY
		);

		$cache->set(
			$cache->makeKey( 'ORES', 'test_stats', 'goodfaith' ),
			$invalidStats,
			\WANObjectCache::TTL_DAY
		);

		MediaWikiServices::getInstance()->resetServiceForTesting( 'MainWANObjectCache' );
		MediaWikiServices::getInstance()->redefineService(
			'MainWANObjectCache',
			function () use ( $cache ) {
				return $cache;
			}
		);
	}

}
