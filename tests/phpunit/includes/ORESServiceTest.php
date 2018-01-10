<?php

namespace ORES\Tests;

use MediaWiki\Logger\LoggerFactory;
use ORES;

/**
 * @group ORES
 * @covers ORES\ORESService
 */
class ORESServiceTest extends \MediaWikiTestCase {

	/**
	 * @var ORES\ORESService
	 */
	protected $oresService;

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'wgOresWikiId' => 'testwiki',
		] );
		$this->oresService = new ORES\ORESService( LoggerFactory::getInstance( 'ORES' ) );
	}

	/**
	 * @covers ORES\ORESService::getUrl
	 */
	public function testServiceUrl() {
		$url = $this->oresService->getUrl();
		$this->assertSame( "https://ores-beta.wmflabs.org/v3/scores/testwiki/", $url );
	}

}
