<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use ORES\ORESService;

/**
 * @group ORES
 * @covers ORES\ORESService
 */
class ORESServiceTest extends \MediaWikiTestCase {

	/**
	 * @var ORESService
	 */
	protected $oresService;

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'wgOresWikiId' => 'testwiki',
		] );
		$this->oresService = new ORESService( LoggerFactory::getInstance( 'ORES' ) );
	}

	/**
	 * @covers ORES\ORESService::getUrl
	 */
	public function testServiceUrl() {
		$url = $this->oresService->getUrl();
		$this->assertSame( "https://ores-beta.wmflabs.org/v3/scores/testwiki/", $url );
	}

	/**
	 * @covers ORES\ORESService::getWikiID
	 */
	public function testGetWikiID() {
		$this->assertSame( 'testwiki', ORESService::getWikiID() );

		$this->setMwGlobals( [ 'wgOresWikiId' => 'testwiki2' ] );
		$this->assertSame( 'testwiki2', ORESService::getWikiID() );

		$this->setMwGlobals( [ 'wgOresWikiId' => null ] );
		$this->assertSame( wfWikiID(), ORESService::getWikiID() );
	}

}
