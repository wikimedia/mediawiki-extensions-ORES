<?php

namespace ORES\Tests\Hooks;

use ChangesList;
use Config;
use EnhancedChangesList;
use FauxRequest;
use FormOptions;
use IContextSource;
use ORES\Hooks\ChangesListHooksHandler;
use ORES\Storage\HashModelLookup;
use RCCacheEntry;
use RecentChange;
use RequestContext;
use SpecialPage;
use User;
use Wikimedia\TestingAccessWrapper;

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

		$modelData = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 7, 'version' => '0.0.3' ],
		];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
		$this->user = static::getTestUser()->getUser();
		$this->user->setOption( 'ores-enabled', 1 );
		$this->user->setOption( 'rcOresDamagingPref', 'maybebad' );
		$this->user->setOption( 'oresHighlight', 1 );
		$this->user->setOption( 'ores-damaging-flag-rc', 1 );
		$this->user->setOption( 'oresRCHideNonDamaging', 1 );
		$this->user->setOption( 'rcenhancedfilters-disable', true );
		$this->user->saveSettings();

		$this->context = self::getContext( $this->user );
	}

	protected function makeRcEntry( $isDamaging = false ) {
		$row = new \stdClass();
		$row->ores_damaging_threshold = 0.2;
		if ( $isDamaging ) {
			$row->ores_damaging_score = 0.3;
		} else {
			$row->ores_damaging_score = 0.1;
		}
		$row->rc_patrolled = 0;
		$row->rc_timestamp = '20150921134808';
		$row->rc_deleted = 0;
		$row->rc_comment = '';
		$row->rc_comment_text = '';
		$row->rc_comment_data = null;
		$row->rc_user = 1;
		$row->rc_user_text = 'Test user';

		$rc = RecentChange::newFromRow( $row );
		return $rc;
	}

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::getScoreRecentChangesList
	 */
	public function testGetScoreRecentChangesList() {
		$this->assertTrue( ChangesListHooksHandler::getScoreRecentChangesList(
			$this->makeRcEntry( true ), $this->context ) );

		$this->assertFalse( ChangesListHooksHandler::getScoreRecentChangesList(
			$this->makeRcEntry( false ), $this->context ) );
	}

	/**
	 * @dataProvider onChangesListSpecialPageQuery_provider
	 * @covers ORES\Hooks\ChangesListHooksHandler::onChangesListSpecialPageQuery
	 */
	public function testOnChangesListSpecialPageQuery( array $modelConfig, array $expectedQuery ) {
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
				[ 'damaging' => [ 'enabled' => false ], 'goodfaith' => [ 'enabled' => false ] ],
				[
					'tables' => [],
					'fields' => [],
					'join_conds' => []
				]
			],
			[
				[ 'damaging' => [ 'enabled' => true ], 'goodfaith' => [ 'enabled' => false ] ],
				[
					'tables' => [
						'ores_damaging_cls' => 'ores_classification'
					],
					'fields' => [
						'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
					],
					'join_conds' => [
						'ores_damaging_cls' => [ 'LEFT JOIN',
							[
								'ores_damaging_cls.oresc_model' => 5,
								'ores_damaging_cls.oresc_rev=rc_this_oldid',
								'ores_damaging_cls.oresc_class' => 1
							]
						]
					]
				]
			],
			[
				[ 'damaging' => [ 'enabled' => false ], 'goodfaith' => [ 'enabled' => true ] ],
				[
					'tables' => [
						'ores_goodfaith_cls' => 'ores_classification'
					],
					'fields' => [
						'ores_goodfaith_score' => 'ores_goodfaith_cls.oresc_probability',
					],
					'join_conds' => [
						'ores_goodfaith_cls' => [ 'LEFT JOIN',
							[
								'ores_goodfaith_cls.oresc_model' => 7,
								'ores_goodfaith_cls.oresc_rev=rc_this_oldid',
								'ores_goodfaith_cls.oresc_class' => 1
							]
						]
					]
				]
			],
			[
				[ 'damaging' => [ 'enabled' => true ], 'goodfaith' => [ 'enabled' => true ] ],
				[
					'tables' => [
						'ores_damaging_cls' => 'ores_classification',
						'ores_goodfaith_cls' => 'ores_classification'
					],
					'fields' => [
						'ores_damaging_score' => 'ores_damaging_cls.oresc_probability',
						'ores_goodfaith_score' => 'ores_goodfaith_cls.oresc_probability',
					],
					'join_conds' => [
						'ores_damaging_cls' => [ 'LEFT JOIN',
							[
								'ores_damaging_cls.oresc_model' => 5,
								'ores_damaging_cls.oresc_rev=rc_this_oldid',
								'ores_damaging_cls.oresc_class' => 1
							]
						],
						'ores_goodfaith_cls' => [ 'LEFT JOIN',
							[
								'ores_goodfaith_cls.oresc_model' => 7,
								'ores_goodfaith_cls.oresc_rev=rc_this_oldid',
								'ores_goodfaith_cls.oresc_class' => 1
							]
						]
					]
				]
			]
		];
	}

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onEnhancedChangesListModifyLineData
	 */
	public function testOnEnhancedChangesListModifyLineDataDamaging() {
		$rc = $this->makeRcEntry( true );
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

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onEnhancedChangesListModifyLineData
	 */
	public function testOnEnhancedChangesListModifyLineDataNonDamaging() {
		$rc = $this->makeRcEntry( false );
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

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onOldChangesListRecentChangesLine
	 */
	public function testOnOldChangesListModifyLineDataDamaging() {
		$rc = $this->makeRcEntry( true );
		$rc = RCCacheEntry::newFromParent( $rc );

		$config = $this->getMockBuilder( Config::class )->getMock();
		$config->expects( $this->any() )
			->method( 'get' )
			->will( $this->returnValue( true ) );

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

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onOldChangesListRecentChangesLine
	 */
	public function testOnOldChangesListModifyLineDataNonDamaging() {
		$rc = $this->makeRcEntry( false );
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

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onChangesListSpecialPageStructuredFilters
	 */
	public function testOnChangesListSpecialPageStructuredFilters_Recentchangeslinked() {
		$changesListSpecialPage = new \SpecialRecentChangesLinked();
		$changesListSpecialPage->setContext( $this->context );
		$wrappedClsp = TestingAccessWrapper::newFromObject( $changesListSpecialPage );
		$wrappedClsp->registerFilters();

		$originalFilters = $wrappedClsp->getFilterGroups();

		ChangesListHooksHandler::onChangesListSpecialPageStructuredFilters( $changesListSpecialPage );

		$updatedFilters = $wrappedClsp->getFilterGroups();

		$this->assertEquals( $originalFilters, $updatedFilters );
	}

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onChangesListSpecialPageStructuredFilters
	 */
	public function testOnChangesListSpecialPageStructuredFilters_Recentchanges() {
		$changesListSpecialPage = new \SpecialRecentChanges();
		$changesListSpecialPage->setContext( $this->context );
		$wrappedClsp = TestingAccessWrapper::newFromObject( $changesListSpecialPage );
		$wrappedClsp->registerFilters();

		ChangesListHooksHandler::onChangesListSpecialPageStructuredFilters( $changesListSpecialPage );

		$damagingFilterGroup = $changesListSpecialPage->getFilterGroup( 'damaging' );
		$this->assertNotNull( $damagingFilterGroup );
		$maybebadFilter = $damagingFilterGroup->getFilter( 'maybebad' );
		$this->assertNotNull( $maybebadFilter );

		$this->assertEquals( 'maybebad', $damagingFilterGroup->getDefault() );

		$goodfaithFilterGroup = $changesListSpecialPage->getFilterGroup( 'goodfaith' );
		$this->assertNull( $goodfaithFilterGroup );
	}

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onChangesListSpecialPageStructuredFilters
	 */
	public function testOnChangesListSpecialPageStructuredFilters_Watchlist() {
		$this->user->setOption( 'oresWatchlistHideNonDamaging', 0 );
		$this->user->setOption( 'oresHighlight', 1 );

		$changesListSpecialPage = new \SpecialWatchlist();
		$changesListSpecialPage->setContext( $this->context );
		$wrappedClsp = TestingAccessWrapper::newFromObject( $changesListSpecialPage );
		$wrappedClsp->registerFilters();

		ChangesListHooksHandler::onChangesListSpecialPageStructuredFilters( $changesListSpecialPage );

		$damagingFilterGroup = $changesListSpecialPage->getFilterGroup( 'damaging' );
		$this->assertNotNull( $damagingFilterGroup );
		$maybebadFilter = $damagingFilterGroup->getFilter( 'maybebad' );
		$this->assertNotNull( $maybebadFilter );

		$this->assertEquals( '', $damagingFilterGroup->getDefault() );

		$filterJsData = $damagingFilterGroup->getFilter( 'likelybad' )->getJsData();
		$this->assertEquals( 'c4', $filterJsData['defaultHighlightColor'] );

		$goodfaithFilterGroup = $changesListSpecialPage->getFilterGroup( 'goodfaith' );
		$this->assertNull( $goodfaithFilterGroup );
	}

	/**
	 * @covers ORES\Hooks\ChangesListHooksHandler::onEnhancedChangesListModifyBlockLineData
	 */
	public function testOnEnhancedChangesListModifyBlockLineData() {
		$ecl = new EnhancedChangesList( $this->context );
		$rc = RCCacheEntry::newFromParent( $this->makeRcEntry( true ) );
		$data = [
			'attribs' => [
				'class' => [],
			],
			'recentChangesFlags' => [],
		];
		$expected = [
			'attribs' => [
				'class' => [ 'damaging' ],
			],
			'recentChangesFlags' => [
				'damaging' => true
			],
		];

		ChangesListHooksHandler::onEnhancedChangesListModifyBlockLineData( $ecl, $data, $rc );

		$this->assertEquals( $expected, $data );
	}

}
