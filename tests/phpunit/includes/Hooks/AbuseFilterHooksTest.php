<?php
namespace ORES\Tests\Hooks;

use MediaWiki\Content\Content;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\VariableGenerator\VariableGeneratorFactory;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Page\WikiPage;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;
use ORES\LiftWingService;
use ORES\ORESService;
use ORES\PreSaveRevisionData;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \ORES\Hooks\AbuseFilterHooks
 * @group Database
 */
class AbuseFilterHooksTest extends MediaWikiIntegrationTestCase {
	private VariableGeneratorFactory $variableGeneratorFactory;
	private VariablesManager $variablesManager;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Abuse Filter' );

		$this->variableGeneratorFactory = AbuseFilterServices::getVariableGeneratorFactory(
			$this->getServiceContainer()
		);

		$this->variablesManager = AbuseFilterServices::getVariablesManager( $this->getServiceContainer() );
	}

	/**
	 * @dataProvider provideDisabledParams
	 */
	public function testShouldDoNothingWhenDisabled(
		bool $isIntegrationEnabled,
		bool $oresUseLiftWing,
		bool $isPageCreation
	): void {
		$this->overrideConfigValues( [
			'ORESRevertRiskAbuseFilterIntegrationEnabled' => $isIntegrationEnabled,
			'ORESUseLiftWing' => $oresUseLiftWing,
		] );

		$oresServiceClass = $oresUseLiftWing ? LiftWingService::class : ORESService::class;
		$this->setService( 'ORESService', $this->createNoOpMock( $oresServiceClass ) );

		$page = $isPageCreation ? $this->getNonexistingTestPage() : $this->getExistingTestPage();
		$user = $this->getTestUser()->getUser();

		$newContent = $this->getServiceContainer()
			->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WIKITEXT )
			->unserializeContent( '== Test ==' );

		// Simulate running AbuseFilter for an edit with the given content.
		$vars = $this->variableGeneratorFactory->newRunGenerator( $user, $page->getTitle() )
			->getEditVars( $newContent, 'Test', SlotRecord::MAIN, $page );

		$score = $this->variablesManager->getVar( $vars, 'revertrisk_score' );

		$this->assertNull( $score->getData() );
	}

	public static function provideDisabledParams(): iterable {
		yield 'Disabled AF integration, LiftWing disabled, page edit' => [ false, false, false ];
		yield 'Disabled AF integration, LiftWing enabled, page edit' => [ false, true, false ];
		yield 'Enabled AF integration, LiftWing disabled, page edit' => [ true, false, false ];
		yield 'Enabled AF integration, LiftWing enabled, page creation' => [ true, false, true ];
	}

	public function testShouldEvaluateScoreForEdit(): void {
		$this->overrideConfigValues( [
			'ORESRevertRiskAbuseFilterIntegrationEnabled' => true,
			'ORESUseLiftWing' => true,
		] );

		$page = $this->getExistingTestPage();
		$user = $this->getTestUser()->getUser();

		$prevContent = $page->getRevisionRecord()->getContent( SlotRecord::MAIN );
		$newContent = $this->getServiceContainer()
			->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WIKITEXT )
			->unserializeContent( '== Test ==' );

		// Configure a LiftWingService mock that expect a call to revertRiskPreSave()
		// with the expected parameters.
		$this->setService(
			'ORESService',
			$this->createMockLiftWingService( $user, $page, $prevContent, $newContent )
		);

		// Simulate running AbuseFilter for an edit with the given content.
		$vars = $this->variableGeneratorFactory->newRunGenerator( $user, $page->getTitle() )
			->getEditVars( $newContent, 'Test', SlotRecord::MAIN, $page );

		$score = $this->variablesManager->getVar( $vars, 'revertrisk_score' );

		$this->assertSame( 0.5, $score->getData() );
	}

	public function testShouldDoNothingForPageMove(): void {
		$this->overrideConfigValues( [
			'ORESRevertRiskAbuseFilterIntegrationEnabled' => true,
			'ORESUseLiftWing' => true,
		] );

		$liftWingService = $this->createNoOpMock( LiftWingService::class );

		$this->setService( 'ORESService', $liftWingService );

		$page = $this->getExistingTestPage();
		$user = $this->getTestUser()->getUser();

		// Simulate running AbuseFilter for a page move.
		$vars = $this->variableGeneratorFactory->newRunGenerator( $user, $page->getTitle() )
			->getMoveVars( Title::makeTitle( NS_MAIN, 'Bar' ), 'Test' );

		$score = $this->variablesManager->getVar( $vars, 'revertrisk_score' );

		$this->assertNull( $score->getData() );
	}

	public function testShouldEvaluateScoreForHistoricalEdit(): void {
		$this->overrideConfigValues( [
			'ORESRevertRiskAbuseFilterIntegrationEnabled' => true,
			'ORESUseLiftWing' => true,
		] );

		$page = $this->getExistingTestPage();
		$user = $this->getTestUser()->getUser();

		$prevContent = $page->getRevisionRecord()->getContent( SlotRecord::MAIN );
		$newContent = $this->getServiceContainer()
			->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WIKITEXT )
			->unserializeContent( '== Test ==' );

		$status = $this->editPage( $page, $newContent, 'Test', NS_MAIN, $user );
		$newRevRecord = $status->getNewRevision();

		// Make a newer edit to verify it's not considered when evaluating the score for a historical edit.
		$this->editPage( $page, 'A newer edit that should be ignored', 'Test', NS_MAIN, $user );

		$rc = RecentChange::newFromConds( [ 'rc_this_oldid' => $newRevRecord->getId() ] );

		// Configure a LiftWingService mock that expect a call to revertRiskPreSave()
		// with the expected parameters.
		$this->setService(
			'ORESService',
			$this->createMockLiftWingService( $user, $page, $prevContent, $newContent )
		);

		// Simulate running AbuseFilter for a given historical edit.
		$viewer = $this->getTestSysop()->getUser();
		$vars = $this->variableGeneratorFactory->newRCGenerator( $rc, $viewer )
			->getVars();

		$score = $this->variablesManager->getVar( $vars, 'revertrisk_score' );

		$this->assertSame( 0.5, $score->getData() );
	}

	public function testShouldDoNothingForHistoricalPageCreation(): void {
		$this->overrideConfigValues( [
			'ORESRevertRiskAbuseFilterIntegrationEnabled' => true,
			'ORESUseLiftWing' => true,
		] );

		$liftWingService = $this->createNoOpMock( LiftWingService::class );

		$this->setService( 'ORESService', $liftWingService );

		// Ensure the edit we'll be inspecting is the first revision of the page.
		$page = $this->getNonExistingTestPage();
		$user = $this->getTestUser()->getUser();

		$status = $this->editPage( $page, 'Test', 'Test', NS_MAIN, $user );
		$newRevRecord = $status->getNewRevision();

		$rc = RecentChange::newFromConds( [ 'rc_this_oldid' => $newRevRecord->getId() ] );

		// Simulate running AbuseFilter for a given historical page creation.
		$viewer = $this->getTestSysop()->getUser();
		$vars = $this->variableGeneratorFactory->newRCGenerator( $rc, $viewer )
			->getVars();

		$score = $this->variablesManager->getVar( $vars, 'revertrisk_score' );

		$this->assertNull( $score->getData() );
	}

	public function testShouldDoNothingForHistoricalPageMove(): void {
		$this->overrideConfigValues( [
			'ORESRevertRiskAbuseFilterIntegrationEnabled' => true,
			'ORESUseLiftWing' => true,
		] );

		$liftWingService = $this->createNoOpMock( LiftWingService::class );

		$this->setService( 'ORESService', $liftWingService );

		$page = $this->getExistingTestPage();
		$user = $this->getTestUser()->getUser();

		$this->getServiceContainer()
			->getMovePageFactory()
			->newMovePage( $page, Title::makeTitle( NS_MAIN, 'Bar' ) )
			->move( $user );

		$rc = RecentChange::newFromConds( [ 'rc_log_type' => 'move' ] );

		// Simulate running AbuseFilter for a given historical page move.
		$viewer = $this->getTestSysop()->getUser();
		$vars = $this->variableGeneratorFactory->newRCGenerator( $rc, $viewer )
			->getVars();

		$score = $this->variablesManager->getVar( $vars, 'revertrisk_score' );

		$this->assertNull( $score->getData() );
	}

	/**
	 * Convenience function for creating a LiftWingService mock that asserts that
	 * revertRiskPreSave() is called with the expected parameters for an edit.
	 *
	 * @param UserIdentity $user
	 * @param WikiPage $page
	 * @param Content $prevContent
	 * @param Content $newContent
	 * @return LiftWingService|MockObject
	 */
	private function createMockLiftWingService(
		UserIdentity $user,
		WikiPage $page,
		Content $prevContent,
		Content $newContent
	): LiftWingService {
		$liftWingService = $this->createMock( LiftWingService::class );
		$liftWingService->expects( $this->once() )
			->method( 'revertRiskPreSave' )
			->willReturnCallback(
				function ( UserIdentity $editor, PreSaveRevisionData $data )
				use ( $user, $page, $prevContent, $newContent ): ?float {
					$this->assertTrue( $user->equals( $editor ) );
					$rawData = $data->jsonSerialize();

					$this->assertSame( -1, $rawData['id'] );
					$this->assertSame( $newContent->serialize(), $rawData['text'] );
					$this->assertSame( 'Test', $rawData['comment'] );
					$this->assertSame( $page->getTitle()->getPageLanguage()->getCode(), $rawData['lang'] );
					$this->assertSame(
						wfTimestamp( TS_ISO_8601, $this->getServiceContainer()
							->getRevisionLookup()
							->getFirstRevision( $page )
							->getTimestamp() ),
						$rawData['page']['first_edit_timestamp']
					);
					$this->assertSame( $page->getId(), $rawData['page']['id'] );
					$this->assertSame( $page->getTitle()->getPrefixedText(), $rawData['page']['title'] );

					$this->assertSame( $prevContent->serialize(), $rawData['parent']['text'] );

					return 0.5;
				}
			);

		return $liftWingService;
	}
}
