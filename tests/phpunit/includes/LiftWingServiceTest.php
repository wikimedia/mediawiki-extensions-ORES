<?php

namespace ORES\Tests;

use MediaWiki\Config\HashConfig;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\WikiMap\WikiMap;
use ORES\LiftWingService;

/**
 * @group ORES
 * @covers \ORES\ORESService
 */
class LiftWingServiceTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @var LiftWingService|null
	 */
	protected ?LiftWingService $lwService;

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'OresLiftWingBaseUrl' => 'https://liftwing.wikimedia.org/',
			'OresWikiId' => 'testwiki',
			'OresFrontendBaseUrl' => null,
		] );
		$this->lwService = new LiftWingService(
			LoggerFactory::getInstance( 'ORES' ),
			$this->getServiceContainer()->getHttpRequestFactory(),
			new HashConfig()
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

		$this->overrideConfigValue( 'OresWikiId', 'testwiki2' );
		$this->assertSame( 'testwiki2', LiftWingService::getWikiID() );

		$this->overrideConfigValue( 'OresWikiId', null );
		$this->assertSame( WikiMap::getCurrentWikiId(), LiftWingService::getWikiID() );
	}

	/**
	 * @covers \ORES\LiftWingService::getBaseUrl
	 */
	public function testGetBaseUrl() {
		$this->assertSame( 'https://liftwing.wikimedia.org/', LiftWingService::getBaseUrl() );

		$this->overrideConfigValue( 'OresLiftWingBaseUrl', 'https://example.com' );
		$this->assertSame( 'https://example.com', LiftWingService::getBaseUrl() );
	}

	/**
	 * @covers \ORES\ORESService::getFrontendBaseUrl()
	 */
	public function testGetFrontendBaseUrl() {
		$this->assertSame( 'https://liftwing.wikimedia.org/', LiftWingService::getFrontendBaseUrl() );

		$this->overrideConfigValue( 'OresFrontendBaseUrl', 'https://example.com' );
		$this->assertSame( 'https://example.com', LiftWingService::getFrontendBaseUrl() );
	}

}
