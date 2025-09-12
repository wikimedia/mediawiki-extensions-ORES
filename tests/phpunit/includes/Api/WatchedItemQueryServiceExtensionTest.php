<?php

namespace ORES\Tests;

use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\User;
use MediaWiki\Watchlist\WatchedItem;
use ORES\Hooks\Api\WatchedItemQueryServiceExtension;
use ORES\Storage\HashModelLookup;
use Wikimedia\Rdbms\Expression;

/**
 * @group ORES
 * @group Database
 * @covers \ORES\Hooks\Api\WatchedItemQueryServiceExtension
 * @covers \ORES\Hooks\Helpers::maybeAddOresReviewConds
 */
class WatchedItemQueryServiceExtensionTest extends \MediaWikiIntegrationTestCase {

	/** @var User */
	protected $user;

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
			'OresModels' => [
				'damaging' => [ 'enabled' => true ],
				'goodfaith' => [ 'enabled' => true ],
				'reverted' => [ 'enabled' => true ],
				'articlequality' => [
					'enabled' => true,
					'namespaces' => [ 0 ],
					'cleanParent' => true,
					'keepForever' => true,
				],
				'wp10' => [
					'enabled' => false,
					'namespaces' => [ 0 ],
					'cleanParent' => true,
					'keepForever' => true,
				],
				'draftquality' => [
					'enabled' => false,
					'namespaces' => [ 0 ],
					'sources' => [ RecentChange::SRC_NEW ],
				],
			],
		] );

		$userOptionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$this->user = static::getTestUser()->getUser();
		$userOptionsManager->setOption( $this->user, 'ores-enabled', 1 );
		$userOptionsManager->setOption( $this->user, 'oresDamagingPref', 'maybebad' );
		$userOptionsManager->setOption( $this->user, 'oresHighlight', 1 );
		$userOptionsManager->setOption( $this->user, 'ores-damaging-flag-rc', 1 );
		$userOptionsManager->saveOptions( $this->user );

		$modelData = [ 'damaging' => [ 'id' => 5, 'version' => '0.0.2' ] ];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
	}

	/**
	 * @covers \ORES\Hooks\Api\WatchedItemQueryServiceExtension::modifyWatchedItemsWithRCInfoQuery
	 */
	public function testModifyWatchedItemsWithRCInfoQuery_review() {
		$options = [
			'filters' => [ 'oresreview' ],
			'includeFields' => [ 'oresscores' ],
			'usedInGenerator' => false,
		];
		$tables = [];
		$fields = [];
		$conds = [];
		$dbOptions = [];
		$joinConds = [];
		$db = $this->getDb();
		$service = new WatchedItemQueryServiceExtension();
		$service->modifyWatchedItemsWithRCInfoQuery(
			$this->user,
			$options,
			$db,
			$tables,
			$fields,
			$conds,
			$dbOptions,
			$joinConds );

		$this->assertEquals( [
			'rc_this_oldid',
			'rc_type',
		], $fields );
		$this->assertEquals( [
			'ores_classification',
		], $tables );
		$this->assertEquals( [
			new Expression( 'oresc_probability', '>', '0.16' ),
		], $conds );
		$this->assertEquals( [
			'ores_classification' => [ 'INNER JOIN', [
				'oresc_rev=rc_this_oldid',
				'oresc_model' => 5,
				'oresc_class' => 1,
			], ],
		], $joinConds );
	}

	/**
	 * @covers \ORES\Hooks\Api\WatchedItemQueryServiceExtension::modifyWatchedItemsWithRCInfoQuery
	 */
	public function testModifyWatchedItemsWithRCInfoQuery_not_review() {
		$options = [
			'filters' => [ '!oresreview' ],
			'includeFields' => [ 'oresscores' ],
			'usedInGenerator' => false,
		];
		$tables = [];
		$fields = [];
		$conds = [];
		$dbOptions = [];
		$joinConds = [];
		$db = $this->getDb();
		$service = new WatchedItemQueryServiceExtension();
		$service->modifyWatchedItemsWithRCInfoQuery(
			$this->user,
			$options,
			$db,
			$tables,
			$fields,
			$conds,
			$dbOptions,
			$joinConds );

		$this->assertEquals( [
			'rc_this_oldid',
			'rc_type',
		], $fields );
		$this->assertEquals( [
			'ores_classification',
		], $tables );
		$this->assertEquals( [
			( new Expression( 'oresc_probability', '<=', '0.16' ) )
				->or( 'oresc_probability', '=', null )
		], $conds );
		$this->assertEquals( [
			'ores_classification' => [ 'LEFT JOIN', [
				'oresc_rev=rc_this_oldid',
				'oresc_model' => 5,
				'oresc_class' => 1,
			], ],
		], $joinConds );
	}

	/**
	 * @covers \ORES\Hooks\Api\WatchedItemQueryServiceExtension::modifyWatchedItemsWithRCInfo
	 */
	public function testModifyWatchedItemsWithRCInfo() {
		$options = [
			'includeFields' => [ 'oresscores' ],
			'usedInGenerator' => false,
		];
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->user, $target, 'Create the page' );
		$revisionRecord = $status->getValue()['revision-record'];
		TestHelper::insertOresData(
			$revisionRecord,
			[ 'damaging' => 0.4 ]
		);
		$items = [
			[
				new WatchedItem( $this->user,
					new TitleValue( NS_MAIN, 'Test123' ),
					'201801020304' ),
				[
					'rc_type' => RC_NEW,
					'rc_this_oldid' => $revisionRecord->getId(),
				],
			],
		];
		$res = [];
		$startFrom = [];
		$db = $this->getDb();
		$service = new WatchedItemQueryServiceExtension();
		$service->modifyWatchedItemsWithRCInfo(
			$this->user, $options, $db, $items, $res, $startFrom );

		$this->assertArrayHasKey( 'oresScores', $items[0][1] );
		$this->assertEquals( [
			'oresc_rev' => (string)$revisionRecord->getId(),
			'oresc_class' => '1',
			'oresc_probability' => '0.400',
			'oresc_model' => '5',
		], get_object_vars( $items[0][1]['oresScores'][0] ) );
		$this->assertSame( [], $res );
		$this->assertSame( [], $startFrom );
	}

}
