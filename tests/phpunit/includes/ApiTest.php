<?php

namespace ORES\Tests;

use ORES;

/**
 * @group ORES
 * @covers ORES\Api
 */
class ApiTest extends \MediaWikiTestCase {

	/**
	 * @var ORES\Api
	 */
	protected $api;

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'wgOresWikiId' => 'testwiki',
		] );
		$this->api = new ORES\Api();
	}

	/**
	 * @covers ORES\Api::getUrl
	 */
	public function testApiUrl() {
		$url = $this->api->getUrl();
		$this->assertSame( "https://ores-beta.wmflabs.org/v3/scores/testwiki/", $url );
	}

}
