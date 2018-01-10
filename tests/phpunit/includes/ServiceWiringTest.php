<?php

namespace ORES\Tests;

use MediaWiki\MediaWikiServices;
use ORES\ORESService;
use ORES\Storage\ModelLookup;
use ORES\Storage\ScoreStorage;
use ORES\ThresholdLookup;

/**
 * @license GNU GPL v2+
 * @author Addshore
 * @author Amir Sarabadani
 */
class ServiceWiringTest extends \MediaWikiTestCase {

	public function provideServices() {
		return [
			[ 'ORESModelLookup', ModelLookup::class ],
			[ 'ORESThresholdLookup', ThresholdLookup::class ],
			[ 'ORESScoreStorage', ScoreStorage::class ],
			[ 'ORESService', ORESService::class ],
		];
	}

	/**
	 * @dataProvider provideServices
	 */
	public function testServiceWiring( $serviceName, $expectedClass ) {
		$service1 = MediaWikiServices::getInstance()->getService( $serviceName );
		$service2 = MediaWikiServices::getInstance()->getService( $serviceName );

		$this->assertInstanceOf( $expectedClass, $service1 );
		$this->assertInstanceOf( $expectedClass, $service2 );
		$this->assertSame( $service1, $service2 );
	}

}
