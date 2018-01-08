<?php

namespace ORES\Tests;

use ORES\ScoreFetcher;
use ORES\Storage\HashModelLookup;

/**
 * @group ORES
 * @group Database
 * @covers ORES\ScoreFetcher
 */
class ScoreFetcherTest extends \MediaWikiTestCase {

	const REVERTED = 2;
	const DAMAGING = 3;
	const GOODFAITH = 4;

	public function setUp() {
		parent::setUp();
		$modelData = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => self::GOODFAITH, 'version' => '0.0.3' ],
		];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
		$this->tablesUsed[] = 'ores_model';
	}

	public function provideTestCheckModelVersion() {
		return [
			[ null, 'damaging', [] ],
			[ null, 'damaging',  [ 'info' => 'foo' ] ],
			[ '0.0.4', 'damaging', [ 'version' => '0.0.4' ] ],
			[ null, 'goodfaith', [ 'version' => '0.0.3' ] ],
			[ '0.0.4', 'goodfaith', [ 'version' => '0.0.4' ] ],
		];
	}

	/**
	 * @dataProvider provideTestCheckModelVersion
	 */
	public function testCheckModelVersion( $expected, $model, array $response ) {
		$scoreFetcher = ScoreFetcher::instance();

		$this->assertSame( $expected, $scoreFetcher->checkModelVersion( $model, $response ) );
	}

	public function testUpdateModelVersion() {
		$dbw = \wfGetDB( DB_MASTER );
		$dbw->insert( 'ores_model',
			[
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.3',
				'oresm_is_current' => 1,
			],
			__METHOD__
		);
		$scoreFetcher = ScoreFetcher::instance();
		$scoreFetcher->updateModelVersion( 'damaging', '0.0.4' );

		$res = wfGetDB( DB_REPLICA )->select(
			'ores_model',
			[ 'oresm_name', 'oresm_version', 'oresm_is_current' ],
			'',
			__METHOD__
		);

		$result = [];
		foreach ( $res as $row ) {
			$result[] = $row;
		}

		$expected = [
			(object)[
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.3',
				'oresm_is_current' => '0'
			],
			(object)[
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.4',
				'oresm_is_current' => '1'
			],
		];
		$this->assertEquals( $expected, $result );
	}

}
