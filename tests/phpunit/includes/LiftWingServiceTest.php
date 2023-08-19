<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\WikiMap\WikiMap;
use ORES\LiftWingService;

/**
 * @group ORES
 * @covers \ORES\ORESService
 */
class LiftWingServiceTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @var LiftWingService
	 */
	protected $liftWingService;

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresLiftWingBaseUrl' => 'https://liftwing.wikimedia.org/',
			'wgOresWikiId' => 'testwiki',
			'wgOresFrontendBaseUrl' => null,
		] );
		$this->lwService = new LiftWingService(
			LoggerFactory::getInstance( 'ORES' ),
			$this->getServiceContainer()->getHttpRequestFactory()
		);
	}

	/**
	 * @covers \ORES\LiftWingService::getUrl
	 */
	public function testServiceUrl() {
		$url = $this->lwService->getUrl( 'damaging' );
		$this->assertSame( "https://liftwing.wikimedia.org/v1/models/testwiki-damaging:predict", $url );
	}

	/**
	 * @covers \ORES\LiftWingService::getWikiID
	 */
	public function testGetWikiID() {
		$this->assertSame( 'testwiki', LiftWingService::getWikiID() );

		$this->setMwGlobals( [ 'wgOresWikiId' => 'testwiki2' ] );
		$this->assertSame( 'testwiki2', LiftWingService::getWikiID() );

		$this->setMwGlobals( [ 'wgOresWikiId' => null ] );
		$this->assertSame( WikiMap::getCurrentWikiId(), LiftWingService::getWikiID() );
	}

	/**
	 * @covers \ORES\LiftWingService::getBaseUrl
	 */
	public function testGetBaseUrl() {
		$this->assertSame( 'https://liftwing.wikimedia.org/', LiftWingService::getBaseUrl() );

		$this->setMwGlobals( [ 'wgOresLiftWingBaseUrl' => 'https://example.com' ] );
		$this->assertSame( 'https://example.com', LiftWingService::getBaseUrl() );
	}

	/**
	 * @covers \ORES\ORESService::getFrontendBaseUrl()
	 */
	public function testGetFrontendBaseUrl() {
		$this->assertSame( 'https://liftwing.wikimedia.org/', LiftWingService::getFrontendBaseUrl() );

		$this->setMwGlobals( [ 'wgOresFrontendBaseUrl' => 'https://example.com' ] );
		$this->assertSame( 'https://example.com', LiftWingService::getFrontendBaseUrl() );
	}

}
