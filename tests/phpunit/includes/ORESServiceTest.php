<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\WikiMap\WikiMap;
use ORES\ORESService;

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

		$this->overrideConfigValues( [
			'OresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'OresWikiId' => 'testwiki',
			'OresFrontendBaseUrl' => null,
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

		$this->overrideConfigValue( 'OresWikiId', 'testwiki2' );
		$this->assertSame( 'testwiki2', ORESService::getWikiID() );

		$this->overrideConfigValue( 'OresWikiId', null );
		$this->assertSame( WikiMap::getCurrentWikiId(), ORESService::getWikiID() );
	}

	/**
	 * @covers \ORES\ORESService::getBaseUrl
	 */
	public function testGetBaseUrl() {
		$this->assertSame( 'https://ores-beta.wmflabs.org/', ORESService::getBaseUrl() );

		$this->overrideConfigValue( 'OresBaseUrl', 'https://example.com' );
		$this->assertSame( 'https://example.com', ORESService::getBaseUrl() );
	}

	/**
	 * @covers \ORES\ORESService::getFrontendBaseUrl()
	 */
	public function testGetFrontendBaseUrl() {
		$this->assertSame( 'https://ores-beta.wmflabs.org/', ORESService::getFrontendBaseUrl() );

		$this->overrideConfigValue( 'OresFrontendBaseUrl', 'https://example.com' );
		$this->assertSame( 'https://example.com', ORESService::getFrontendBaseUrl() );
	}

}
