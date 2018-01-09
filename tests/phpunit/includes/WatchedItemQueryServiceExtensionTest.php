<?php

namespace ORES\Tests;

use ORES\Storage\HashModelLookup;
use ORES\WatchedItemQueryServiceExtension;

/**
 * @group ORES
 * @covers ORES\WatchedItemQueryServiceExtension
 */
class WatchedItemQueryServiceExtensionTest extends \MediaWikiTestCase {

	protected $user;

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

		$modelData = [ 'damaging' => [ 'id' => 5, 'version' => '0.0.2' ] ];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
	}

	/**
	 * @covers ORES\WatchedItemQueryServiceExtension::modifyWatchedItemsWithRCInfoQuery
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
		$db = wfGetDB( DB_REPLICA );
		$service = new WatchedItemQueryServiceExtension();
		$service->modifyWatchedItemsWithRCInfoQuery(
			$this->user, $options, $db, $tables, $fields, $conds,
			$dbOptions, $joinConds );

		$this->assertEquals( [
			'rc_this_oldid',
			'rc_type',
		], $fields );
		$this->assertEquals( [
			'ores_model',
			'ores_classification',
		], $tables );
		$this->assertEquals( [
			'oresc_probability > \'0.16\'',
		], $conds );
		$this->assertEquals( [
			'ores_classification' => [ 'INNER JOIN', [
				'rc_this_oldid=oresc_rev',
				'oresc_model' => 5,
				'oresc_class' => 1,
			], ],
		], $joinConds );
	}

	/**
	 * @covers ORES\WatchedItemQueryServiceExtension::modifyWatchedItemsWithRCInfoQuery
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
		$db = wfGetDB( DB_REPLICA );
		$service = new WatchedItemQueryServiceExtension();
		$service->modifyWatchedItemsWithRCInfoQuery(
			$this->user, $options, $db, $tables, $fields, $conds,
			$dbOptions, $joinConds );

		$this->assertEquals( [
			'rc_this_oldid',
			'rc_type',
		], $fields );
		$this->assertEquals( [
			'ores_model',
			'ores_classification',
		], $tables );
		$this->assertEquals( [
			'(oresc_probability <= \'0.16\') OR (oresc_probability IS NULL)',
		], $conds );
		$this->assertEquals( [
			'ores_classification' => [ 'LEFT JOIN', [
				'rc_this_oldid=oresc_rev',
				'oresc_model' => 5,
				'oresc_class' => 1,
			], ],
		], $joinConds );
	}

}
