<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use ORES\ORESService;
use WikiMap;

/**
 * @group ORES
 * @covers \ORES\ORESService
 */
class ORESServiceTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @var ORESService
	 */
	protected $oresService;

	protected function setUp(): void {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'wgOresWikiId' => 'testwiki',
			'wgOresFrontendBaseUrl' => null,
		] );
		$this->oresService = new ORESService(
			LoggerFactory::getInstance( 'ORES' ),
			$this->getServiceContainer()->getHttpRequestFactory()
		);
	}

	/**
	 * @covers \ORES\ORESService::getUrl
	 */
	public function testServiceUrl() {
		$url = $this->oresService->getUrl();
		$this->assertSame( "https://ores-beta.wmflabs.org/v3/scores/testwiki/", $url );
	}

	/**
	 * @covers \ORES\ORESService::getWikiID
	 */
	public function testGetWikiID() {
		$this->assertSame( 'testwiki', ORESService::getWikiID() );

		$this->setMwGlobals( [ 'wgOresWikiId' => 'testwiki2' ] );
		$this->assertSame( 'testwiki2', ORESService::getWikiID() );

		$this->setMwGlobals( [ 'wgOresWikiId' => null ] );
		$this->assertSame( WikiMap::getCurrentWikiId(), ORESService::getWikiID() );
	}

	/**
	 * @covers \ORES\ORESService::getBaseUrl
	 */
	public function testGetBaseUrl() {
		$this->assertSame( 'https://ores-beta.wmflabs.org/', ORESService::getBaseUrl() );

		$this->setMwGlobals( [ 'wgOresBaseUrl' => 'https://example.com' ] );
		$this->assertSame( 'https://example.com', ORESService::getBaseUrl() );
	}

	/**
	 * @covers \ORES\ORESService::getFrontendBaseUrl()
	 */
	public function testGetFrontendBaseUrl() {
		$this->assertSame( 'https://ores-beta.wmflabs.org/', ORESService::getFrontendBaseUrl() );

		$this->setMwGlobals( [ 'wgOresFrontendBaseUrl' => 'https://example.com' ] );
		$this->assertSame( 'https://example.com', ORESService::getFrontendBaseUrl() );
	}

}
