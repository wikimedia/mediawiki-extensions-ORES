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
		$mockOresService = MockOresServiceBuilder::getORESServiceMock( $this );
		$this->setService( 'ORESService', $mockOresService );
		$this->setMwGlobals( [
			'wgOresModels' => [ 'damaging' => true ],
		] );
		$this->tablesUsed[] = 'ores_model';
	}

	public function provideTestGetScores() {
		$firstCase = [
			'123' => [
				'damaging' => [ 'score' => [
					'prediction' => false,
					'probability' => [ 'true' => 0.32, 'false' => 0.68 ]
				] ]
			]
		];
		$secondCase = $firstCase;
		$secondCase['123']['goodfaith'] = [
			'score' => [
				'prediction' => false,
				'probability' => [ 'true' => 0.32, 'false' => 0.68 ]
			]
		];
		return [
			[ $firstCase, 123, 'damaging', true, null ],
			[ $secondCase, 123, 'damaging|goodfaith', true, null ],
			[ $firstCase, 123, 'damaging', false, null ],
			[ $secondCase, 123, 'damaging|goodfaith', false, null ],
			[ $firstCase, 123, null, true, null ],
			[ $firstCase, 123, null, false, null ],
		];
	}

	/**
	 * @dataProvider provideTestGetScores
	 * @covers ORES\ScoreFetcher::getScores
	 */
	public function testGetScores( $expected, $revisions, $models, $precache, $originalRequest ) {
		$scoreFetcher = ScoreFetcher::instance();
		$result = $scoreFetcher->getScores( $revisions, $models, $precache, $originalRequest );
		$this->assertEquals( $expected, $result );
		$res = wfGetDB( DB_REPLICA )->select(
			'ores_model',
			[ 'oresm_name', 'oresm_version', 'oresm_is_current' ],
			'',
			__METHOD__
		);

		$result = iterator_to_array( $res, false );

		if ( $models === null ) {
			global $wgOresModels;
			$models = implode( '|', array_keys( array_filter( $wgOresModels ) ) );
		}

		$expected = [];
		foreach ( explode( '|', $models ) as $model ) {
			$expected[] = (object)[
				'oresm_name' => $model,
				'oresm_version' => '0.0.4',
				'oresm_is_current' => '1'
			];
		}
		$this->assertEquals( $expected, $result );
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
	 * @covers ORES\ScoreFetcher::checkModelVersion
	 */
	public function testCheckModelVersion( $expected, $model, array $response ) {
		$scoreFetcher = ScoreFetcher::instance();

		$this->assertSame( $expected, $scoreFetcher->checkModelVersion( $model, $response ) );
	}

	/**
	 * @covers ORES\ScoreFetcher::updateModelVersion
	 */
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

		$result = iterator_to_array( $res, false );

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