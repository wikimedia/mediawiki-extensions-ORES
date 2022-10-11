<?php

namespace ORES\Tests;

use ORES\Services\FetchScoreJob;
use ORES\Services\ScoreFetcher;
use ORES\Storage\HashModelLookup;
use Title;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Services\FetchScoreJob
 */
class FetchScoreJobTest extends \MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'ores_classification';
		$this->setMwGlobals( [
			'wgOresWikiId' => 'testwiki',
		] );

		$modelData = [
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 7, 'version' => '0.0.3' ],
		];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
	}

	public function testDeduplicationInfo() {
		$params = [
			'revid' => 12345,
			'originalRequest' => [
				'ip' => '127.0.0.1',
				'userAgent' => 'A Really dummy user agent',
			],
			'models' => 'damaging|badfaith',
			'precache' => true,
		];

		$title = Title::makeTitle( NS_MAIN, 'Bar' );

		$expected = [
			'type' => 'ORESFetchScoreJob',
			'params' => [
				'revid' => 12345,
				'precache' => true,
				'models' => 'damaging|badfaith',
				'originalRequest' => 'Dummy data',
				'namespace' => NS_MAIN,
				'title' => 'Bar',
			],
		];

		$jobInfo = ( new FetchScoreJob( $title, $params ) )->getDeduplicationInfo();
		$jobInfo['params']['originalRequest'] = 'Dummy data';

		$this->assertEquals( $expected, $jobInfo );
	}

	/**
	 * @covers ORES\Services\FetchScoreJob::run
	 */
	public function testRun() {
		$dbw = \wfGetDB( DB_PRIMARY );
		$dbw->delete( 'ores_classification', '*' );

		$params = [
			'revid' => 17,
			'models' => [ 'damaging', 'goodfaith' ],
			'precache' => true
		];

		$scoreFetcher = $this->createMock( ScoreFetcher::class );

		$serviceResult = [
			'17' => [
				'damaging' => [
					'score' => [
						'prediction' => false,
						'probability' => [
							'false' => 0.7594447267198007,
							'true' => 0.2405552732801993
						]
					]
				],
				'goodfaith' => [
					'score' => [
						'prediction' => true,
						'probability' => [
							'false' => 0.2512061183891259,
							'true' => 0.7487938816108741
						]
					]
				]
			]
		];
		$scoreFetcher->expects( $this->once() )
			->method( 'getScores' )
			->with( 17, [ 'damaging', 'goodfaith' ], true )
			->willReturn( $serviceResult );

		$job = new FetchScoreJob( $this->createMock( Title::class ), $params );
		$job->setScoreFetcher( $scoreFetcher );

		$job->run();

		$this->assertSelect(
			'ores_classification',
			[
				'oresc_rev',
				'oresc_model',
				'oresc_class',
				'oresc_probability',
				'oresc_is_predicted'
			],
			[ 'oresc_rev' => 17 ],
			[
				[
					17,
					5,
					1,
					0.241,
					0,
				],
				[
					17,
					7,
					1,
					0.749,
					1,
				]
			],
			[ 'ORDER BY' => 'oresc_probability' ]
		);
	}

}
