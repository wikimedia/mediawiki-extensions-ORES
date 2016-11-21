<?php

namespace ORES\Tests;

use ORES;

/**
 * @group ORES
 * @covers ORES\Api
 */
class OresApiTest extends \MediaWikiTestCase {

	/**
	 * @var ORES\Api
	 */
	protected $api;

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'wgOresWikiId' => 'testwiki'
		] );
		$this->api = new ORES\Api();
	}

	public function testApiUrl() {
		$url = $this->api->getUrl();
		$this->assertSame( "https://ores-beta.wmflabs.org/scores/testwiki/", $url );
	}

}
