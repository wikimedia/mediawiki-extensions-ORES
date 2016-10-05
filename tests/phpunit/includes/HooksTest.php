<?php
namespace ORES\Tests;

use ChangesListSpecialPage;
use EnhancedChangesList;
use FormOptions;
use IContextSource;
use RCCacheEntry;
use RecentChange;
use RequestContext;
use ORES;

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

		$this->context = self::getContext();
	}

	public function testOresEnabled() {
		$prefs = [];
		ORES\Hooks::onGetBetaFeaturePreferences( $this->user, $prefs );
		$this->assertArrayHasKey( 'ores-enabled', $prefs );

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

	/**
	 * @return IContextSource
	 */
	private static function getContext() {

		$context = new RequestContext();

		$context->setLanguage( 'en' );

		return $context;
	}
}
