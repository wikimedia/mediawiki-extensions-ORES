<?php

namespace ORES\Tests\Hooks;

use ChangesList;
use Config;
use EnhancedChangesList;
use FauxRequest;
use FormOptions;
use IContextSource;
use ORES\Hooks\ChangesListHooksHandler;
use RCCacheEntry;
use RecentChange;
use RequestContext;
use SpecialPage;
use User;

/**
 * @group ORES
 * @covers ORES\Hooks\ChangesListHooksHandler
 */
class ChangesListHooksHandlerTest extends \MediaWikiTestCase {

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

	public function testOresRCObj() {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		$row->ores_damaging_score = 0.3;
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;
		$row->rc_comment = '';
		$row->rc_comment_text = '';
		$row->rc_comment_data = null;

		$rc = RecentChange::newFromRow( $row );
		$this->assertTrue( ChangesListHooksHandler::getScoreRecentChangesList( $rc, $this->context ) );

		$row->ores_damaging_threshold = 0.4;
		$rc = RecentChange::newFromRow( $row );
		$this->assertFalse( ChangesListHooksHandler::getScoreRecentChangesList( $rc, $this->context ) );
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
		ChangesListHooksHandler::onChangesListSpecialPageQuery(
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
		$row->rc_comment = '';
		$row->rc_comment_text = '';
		$row->rc_comment_data = null;
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

		ChangesListHooksHandler::onEnhancedChangesListModifyLineData(
			$ecl,
			$data,
			$block,
			$rc,
			$classes
		);

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
		$row->rc_comment = '';
		$row->rc_comment_text = '';
		$row->rc_comment_data = null;
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

		ChangesListHooksHandler::onEnhancedChangesListModifyLineData(
			$ecl,
			$data,
			$block,
			$rc,
			$classes
		);

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
		$row->rc_comment = '';
		$row->rc_comment_text = '';
		$row->rc_comment_data = null;
		$rc = RecentChange::newFromRow( $row );
		$rc = RCCacheEntry::newFromParent( $rc );

		$config = $this->getMockBuilder( Config::class )->getMock();
		$config->expects( $this->any() )
			->method( 'get' )
			->will( $this->returnValue( false ) );

		$cl = $this->getMockBuilder( ChangesList::class )
			->disableOriginalConstructor()
			->getMock();

		$cl->expects( $this->any() )
			->method( 'getUser' )
			->will( $this->returnValue( $this->user ) );

		$cl->expects( $this->any() )
			->method( 'getRequest' )
			->will( $this->returnValue( new FauxRequest() ) );

		$cl->expects( $this->any() )
			->method( 'getConfig' )
			->will( $this->returnValue( $config ) );

		$cl->expects( $this->any() )
			->method( 'getTitle' )
			->will( $this->returnValue( SpecialPage::getTitleFor( 'Recentchanges' ) ) );

		$cl->expects( $this->any() )
			->method( 'getContext' )
			->will( $this->returnValue( $this->context ) );

		$classes = [];

		$s = ' <span class="mw-changeslist-separator">. .</span> ';
		ChangesListHooksHandler::onOldChangesListRecentChangesLine( $cl, $s, $rc, $classes );

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
		$row->rc_comment = '';
		$row->rc_comment_text = '';
		$row->rc_comment_data = null;
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
		ChangesListHooksHandler::onOldChangesListRecentChangesLine( $cl, $s, $rc, $classes );

		$this->assertSame( ' <span class="mw-changeslist-separator">. .</span> ', $s );
		$this->assertSame( [], $classes );
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

}
