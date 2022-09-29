<?php

namespace ORES\Tests\Api;

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use ORES\Storage\HashModelLookup;
use ORES\Tests\TestHelper;
use TitleValue;

/**
 * @group API
 * @group Database
 * @group medium
 *
 * @covers \ORES\Hooks\Api\ApiHooksHandler
 * @covers \ORES\Hooks\Api\ApiQueryORES
 */
class ApiIntegrationTest extends \ApiTestCase {
	private $ORESuser;

	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );

		$this->tablesUsed = TestHelper::getTablesUsed();
	}

	protected function setUp(): void {
		parent::setUp();

		$this->ORESuser = $this->getMutableTestUser()->getUser();

		TestHelper::clearOresTables();

		$this->setMwGlobals(
			[
				'wgOresModels' => [
					'damaging' => [ 'enabled' => true ],
					'goodfaith' => [ 'enabled' => true ],
					'reverted' => [ 'enabled' => true ],
					'articlequality' => [ 'enabled' => true ],
					'draftquality' => [ 'enabled' => false ]
				],
				'wgOresModelClasses' => [
					'damaging' => [ 'false' => 0, 'true' => 1 ],
					'goodfaith' => [ 'false' => 0, 'true' => 1 ],
					'reverted' => [ 'false' => 0, 'true' => 1 ],
					'articlequality' => [ 'B' => 0, 'C' => 1, 'FA' => 2, 'GA' => 3, 'Start' => 4 ]
				],
				'wgOresFiltersThresholds' => [
					'damaging' => [
						'likelygood' => [ "min" => 0, "max" => 0.3 ],
						'maybebad' => [ "min" => 0.4, "max" => 1 ],
						'likelybad' => [ "min" => 0.5, "max" => 1 ],
						'verylikelybad' => [ "min" => 0.6, "max" => 1 ]
					],
					'goodfaith' => [
						'likelygood' => [ "min" => 0, "max" => 0.3 ],
						'maybebad' => [ "min" => 0.5, "max" => 1 ],
						'likelybad' => [ "min" => 0.6, "max" => 1 ],
						'verylikelybad' => [ "min" => 0.7, "max" => 1 ]
					],
				],
				'wgOresWikiId' => 'testwiki',
				'wgOresBaseUrl' => 'https://make.ores.great.again/',
				'wgOresExcludeBots' => true,
			]
		);
		$modelData = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 7, 'version' => '0.0.3' ],
		];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
	}

	public function testListRecentChanges_returnsMetaORES() {
		$result = $this->doApiRequest(
			[ 'action' => 'query', 'meta' => 'ores' ],
			null,
			false,
			$this->ORESuser
		);
		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'ores', $result[0]['query'] );
		$result[0]['query']['ores']['namespaces'] = [];
		$expected = [
			'baseurl' => 'https://make.ores.great.again/',
			'wikiid' => 'testwiki',
			'models' => [
				'reverted' => [ 'version' => '0.0.1' ],
				'damaging' => [ 'version' => '0.0.2' ],
				'goodfaith' => [ 'version' => '0.0.3' ]
			],
			'excludebots' => true,
			'damagingthresholds' => [
				'maybebad' => 0.4,
				'likelybad' => 0.5,
				'verylikelybad' => 0.6
			],
			'namespaces' => []
		];
		$this->assertSame( $expected, $result[0]['query']['ores'] );
	}

	public function testListRecentChanges_getOresScores() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcprop' => 'oresscores|ids' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 1, $result[0]['query']['recentchanges'] );

		$expected = [
			'damaging' => [ 'true' => 0.4, 'false' => 0.6 ],
			'goodfaith' => [ 'true' => 0.7, 'false' => 0.3 ],
		];
		$this->assertEqualsWithDelta( $expected, $result[0]['query']['recentchanges'][0]['oresscores'], 1e-9 );
	}

	private function getLoggedInTestUser() {
		return $this->ORESuser;
	}

	private function doListRecentChangesRequest( array $params = [] ) {
		return $this->doApiRequest(
			array_merge(
				[ 'action' => 'query', 'list' => 'recentchanges' ],
				$params
			),
			null,
			false,
			$this->ORESuser
		);
	}

	public function testListRecentChanges_showOresReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.6, 'goodfaith' => 0.3 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcshow' => 'oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 1, $result[0]['query']['recentchanges'] );

		$item = $result[0]['query']['recentchanges'][0];
		$this->assertSame( 'new', $item['type'] );
		$this->assertSame( 0, $item['ns'] );
		$this->assertSame( 'ORESApiIntegrationTestPage', $item['title'] );
		$this->assertSame( $status->getValue()['revision-record']->getId(), $item['revid'] );
	}

	public function testListRecentChanges_showOresReviewNotNeedingReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcshow' => 'oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 0, $result[0]['query']['recentchanges'] );
	}

	public function testListRecentChanges_showNotOresReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.6, 'goodfaith' => 0.3 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcshow' => '!oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 0, $result[0]['query']['recentchanges'] );
	}

	public function testListRecentChanges_showNotOresReviewNotNeedingReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcshow' => '!oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 1, $result[0]['query']['recentchanges'] );

		$item = $result[0]['query']['recentchanges'][0];
		$this->assertSame( 'new', $item['type'] );
		$this->assertSame( 0, $item['ns'] );
		$this->assertSame( 'ORESApiIntegrationTestPage', $item['title'] );
		$this->assertSame( $status->getValue()['revision-record']->getId(), $item['revid'] );
	}

	private function getWatchedItemStore() {
		return MediaWikiServices::getInstance()->getWatchedItemStore();
	}

	/**
	 * @param UserIdentity $user
	 * @param LinkTarget[] $targets
	 */
	private function watchPages( UserIdentity $user, array $targets ) {
		$store = $this->getWatchedItemStore();
		$store->addWatchBatchForUser( $user, $targets );
	}

	private function doListWatchlistRequest( array $params = [], $user = null ) {
		if ( $user === null ) {
			$user = $this->getLoggedInTestUser();
		}
		return $this->doApiRequest(
			array_merge(
				[ 'action' => 'query', 'list' => 'watchlist' ],
				$params
			), null, false, $user
		);
	}

	public function testListWatchlist_getOresScores() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);
		$this->watchPages( $this->getLoggedInTestUser(), [ $target ] );

		$result = $this->doListWatchlistRequest( [ 'wlprop' => 'oresscores|ids' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'watchlist', $result[0]['query'] );
		$this->assertCount( 1, $result[0]['query']['watchlist'] );

		$expected = [
			'damaging' => [ 'true' => 0.4, 'false' => 0.6 ],
			'goodfaith' => [ 'true' => 0.7, 'false' => 0.3 ],
		];
		$this->assertEqualsWithDelta( $expected, $result[0]['query']['watchlist'][0]['oresscores'], 1e-9 );
	}

	public function testListWatchlist_showOresReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.6, 'goodfaith' => 0.3 ]
		);
		$this->watchPages( $this->getLoggedInTestUser(), [ $target ] );

		$result = $this->doListWatchlistRequest( [ 'wlshow' => 'oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'watchlist', $result[0]['query'] );
		$this->assertCount( 1, $result[0]['query']['watchlist'] );

		$item = $result[0]['query']['watchlist'][0];
		$this->assertSame( 'new', $item['type'] );
		$this->assertSame( 0, $item['ns'] );
		$this->assertSame( 'ORESApiIntegrationTestPage', $item['title'] );
		$this->assertSame( $status->getValue()['revision-record']->getId(), $item['revid'] );
	}

	public function testListWatchlist_showOresReviewNotNeedingReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);
		$this->watchPages( $this->getLoggedInTestUser(), [ $target ] );

		$result = $this->doListWatchlistRequest( [ 'wlshow' => 'oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'watchlist', $result[0]['query'] );
		$this->assertCount( 0, $result[0]['query']['watchlist'] );
	}

	public function testListWatchlist_showNotOresReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.6, 'goodfaith' => 0.3 ]
		);
		$this->watchPages( $this->getLoggedInTestUser(), [ $target ] );

		$result = $this->doListWatchlistRequest( [ 'wlshow' => '!oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'watchlist', $result[0]['query'] );
		$this->assertCount( 0, $result[0]['query']['watchlist'] );
	}

	public function testListWatchlist_showNotOresReviewNotNeedingReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = TestHelper::doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		TestHelper::insertOresData(
			$status->getValue()['revision-record'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);
		$this->watchPages( $this->getLoggedInTestUser(), [ $target ] );

		$result = $this->doListWatchlistRequest( [ 'wlshow' => '!oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'watchlist', $result[0]['query'] );
		$this->assertCount( 1, $result[0]['query']['watchlist'] );

		$item = $result[0]['query']['watchlist'][0];
		$this->assertSame( 'new', $item['type'] );
		$this->assertSame( 0, $item['ns'] );
		$this->assertSame( 'ORESApiIntegrationTestPage', $item['title'] );
		$this->assertSame( $status->getValue()['revision-record']->getId(), $item['revid'] );
	}

}
