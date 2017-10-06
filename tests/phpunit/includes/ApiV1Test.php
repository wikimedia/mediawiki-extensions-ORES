<?php

namespace ORES\Tests;

use ORES;

/**
 * @group ORES
 * @covers ORES\ApiV1
 */
class OresApiV1Test extends \MediaWikiTestCase {

	/**
	 * @var ORES\ApiV1
	 */
	protected $api;

	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( [
			'wgOresBaseUrl' => 'https://ores-beta.wmflabs.org/',
			'wgOresWikiId' => 'testwiki',
		] );
		$this->api = new ORES\ApiV1();
	}

	public function testApiUrl() {
		$url = $this->api->getUrl();
		$this->assertSame( "https://ores-beta.wmflabs.org/scores/testwiki/", $url );
	}

	public function testApiUrlWithModel() {
		$url = $this->api->getUrl( 'damaging' );
		$this->assertSame( "https://ores-beta.wmflabs.org/scores/testwiki/damaging/", $url );
	}

}
