<?php

namespace ORES\Tests\Api;

use ContentHandler;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use ORES\Storage\HashModelLookup;
use ORES\Storage\ModelLookup;
use Revision;
use Title;
use TitleValue;
use User;
use WikiPage;

/**
 * @group API
 * @group Database
 * @group medium
 *
 * @covers ORES\Hooks\ApiHooksHandler
 * @covers ORES\ApiQueryORES
 */
class ApiIntegrationTest extends \ApiTestCase {

	public function __construct( $name = null, array $data = [], $dataName = '' ) {
		parent::__construct( $name, $data, $dataName );

		$this->tablesUsed[] = 'recentchanges';
		$this->tablesUsed[] = 'page';
		$this->tablesUsed[] = 'ores_model';
		$this->tablesUsed[] = 'ores_classification';
	}

	protected function setUp() {
		parent::setUp();

		self::$users['ORESApiIntegrationTestUser'] = $this->getMutableTestUser();
		$this->doLogin( 'ORESApiIntegrationTestUser' );
		wfGetDB( DB_MASTER )->delete( 'recentchanges', '*', __METHOD__ );
		wfGetDB( DB_MASTER )->delete( 'ores_model', '*', __METHOD__ );
		wfGetDB( DB_MASTER )->delete( 'ores_classification', '*', __METHOD__ );

		$this->setMwGlobals(
			[
				'wgOresModels' => [
					'damaging' => true,
					'goodfaith' => true,
					'reverted' => true,
					'wp10' => true,
					'draftquality' => false ],
				'wgOresModelClasses' => [
					'damaging' => [ 'false' => 0, 'true' => 1 ],
					'goodfaith' => [ 'false' => 0, 'true' => 1 ],
					'reverted' => [ 'false' => 0, 'true' => 1 ],
					'wp10' => [ 'B' => 0, 'C' => 1, 'FA' => 2, 'GA' => 3, 'Start' => 4 ]
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
				false
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
		$status = $this->doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		$this->insertOresData(
			$status->getValue()['revision'],
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
		$this->assertEquals( $result[0]['query']['recentchanges'][0]['oresscores'], $expected );
	}

	private function doPageEdit( User $user, LinkTarget $target, $summary ) {
		static $i = 0;

		$title = Title::newFromLinkTarget( $target );
		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( __CLASS__ . $i++, $title ),
			$summary,
			0,
			false,
			$user
		);
		if ( !$status->isOK() ) {
			$this->fail();
		}
		return $status;
	}

	private function getLoggedInTestUser() {
		return self::$users['ORESApiIntegrationTestUser']->getUser();
	}

	private function insertOresData( Revision $revision, $scores ) {
		/** @var ModelLookup $modelLookup */
		$modelLookup = MediaWikiServices::getInstance()->getService( 'ORESModelLookup' );
		// TODO: Use ScoreStorage
		$dbData = [];
		foreach ( $scores as $modelName => $score ) {
			$dbData[] = [
				'oresc_rev' => $revision->getId(),
				'oresc_model' => $modelLookup->getModelId( $modelName ),
				'oresc_class' => 1,
				'oresc_probability' => $score,
				'oresc_is_predicted' => 0
			];
		}
		wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData );
	}

	private function doListRecentChangesRequest( array $params = [] ) {
		return $this->doApiRequest(
			array_merge(
				[ 'action' => 'query', 'list' => 'recentchanges' ],
				$params
			),
			null,
			false
		);
	}

	public function testListRecentChanges_showOresReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = $this->doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		$this->insertOresData(
			$status->getValue()['revision'],
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
		$this->assertSame( $status->getValue()['revision']->getId(), $item['revid'] );
	}

	public function testListRecentChanges_showOresReviewNotNeedingReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = $this->doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		$this->insertOresData(
			$status->getValue()['revision'],
			[ 'damaging' => 0.4, 'goodfaith' => 0.7 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcshow' => 'oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 0, $result[0]['query']['recentchanges'] );
	}

	public function testListRecentChanges_showNotOresReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = $this->doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		$this->insertOresData(
			$status->getValue()['revision'],
			[ 'damaging' => 0.6, 'goodfaith' => 0.3 ]
		);

		$result = $this->doListRecentChangesRequest( [ 'rcshow' => '!oresreview' ] );

		$this->assertArrayHasKey( 'query', $result[0] );
		$this->assertArrayHasKey( 'recentchanges', $result[0]['query'] );
		$this->assertCount( 0, $result[0]['query']['recentchanges'] );
	}

	public function testListRecentChanges_showNotOresReviewNotNeedingReview() {
		$target = new TitleValue( 0, 'ORESApiIntegrationTestPage' );
		$status = $this->doPageEdit( $this->getLoggedInTestUser(), $target, 'Create the page' );
		$this->insertOresData(
			$status->getValue()['revision'],
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
		$this->assertSame( $status->getValue()['revision']->getId(), $item['revid'] );
	}

}
