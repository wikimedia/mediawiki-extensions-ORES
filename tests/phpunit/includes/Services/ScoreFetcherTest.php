<?php

namespace ORES\Tests;

use ORES\Services\ScoreFetcher;
use ORES\Storage\HashModelLookup;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Services\ScoreFetcher
 */
class ScoreFetcherTest extends \MediaWikiIntegrationTestCase {

	private const REVERTED = 2;
	private const DAMAGING = 3;
	private const GOODFAITH = 4;

	protected function setUp(): void {
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
			'wgOresModels' => [ 'damaging' => [ 'enabled' => true ] ],
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
			[ $firstCase, 123, 'damaging', true ],
			[ $secondCase, 123, 'damaging|goodfaith', true ],
			[ $firstCase, 123, 'damaging', false ],
			[ $secondCase, 123, 'damaging|goodfaith', false ],
			[ $firstCase, 123, null, true ],
			[ $firstCase, 123, null, false ],
		];
	}

	/**
	 * @dataProvider provideTestGetScores
	 * @covers ORES\Services\ScoreFetcher::getScores
	 */
	public function testGetScores( $expected, $revisions, $models, $precache ) {
		$scoreFetcher = ScoreFetcher::instance();
		$result = $scoreFetcher->getScores( $revisions, $models, $precache );
		$this->assertEqualsWithDelta( $expected, $result, 1e-9 );
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
	 * @covers ORES\Services\ScoreFetcher::checkModelVersion
	 */
	public function testCheckModelVersion( $expected, $model, array $response ) {
		$scoreFetcher = ScoreFetcher::instance();

		$this->assertSame( $expected, $scoreFetcher->checkModelVersion( $model, $response ) );
	}

	/**
	 * @covers ORES\Services\ScoreFetcher::updateModelVersion
	 */
	public function testUpdateModelVersion() {
		$dbw = \wfGetDB( DB_PRIMARY );
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
