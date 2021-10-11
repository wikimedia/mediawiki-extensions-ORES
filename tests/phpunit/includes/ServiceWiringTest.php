<?php

namespace ORES\Tests;

use MediaWiki\MediaWikiServices;
use ORES\ORESService;
use ORES\Storage\ModelLookup;
use ORES\Storage\ScoreStorage;
use ORES\Storage\SqlScoreLookup;
use ORES\Storage\ThresholdLookup;

/**
 * @license GPL-2.0-or-later
 * @author Addshore
 * @author Amir Sarabadani
 * @coversNothing
 */
class ServiceWiringTest extends \MediaWikiIntegrationTestCase {

	public function provideServices() {
		return [
			[ 'ORESModelLookup', ModelLookup::class ],
			[ 'ORESThresholdLookup', ThresholdLookup::class ],
			[ 'ORESScoreStorage', ScoreStorage::class ],
			[ 'ORESService', ORESService::class ],
			[ 'ORESScoreLookup', SqlScoreLookup::class ],
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
