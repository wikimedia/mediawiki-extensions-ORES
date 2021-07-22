<?php

namespace ORES\Tests\Storage;

use MediaWiki\MediaWikiServices;
use MediaWikiLangTestCase;
use ORES\Storage\HashModelLookup;
use ORES\Storage\SqlScoreLookup;
use ORES\Tests\TestHelper;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Storage\SqlScoreLookup
 */
class SqlScoreLookupTest extends MediaWikiLangTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'ores_classification';
	}

	/**
	 * @covers ORES\Storage\SqlScoreLookup::getScores
	 */
	public function testGetScores() {
		$modelData = [
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 6, 'version' => '0.0.3' ],
		];
		$this->setService( 'ORESModelLookup', new HashModelLookup( $modelData ) );
		TestHelper::insertOresData( 123, [ 'damaging' => 0.45, 'goodfaith' => 0.6 ] );
		TestHelper::insertOresData( 223, [ 'damaging' => 0.666, 'goodfaith' => 0.7 ] );
		$storage = new SqlScoreLookup(
			new HashModelLookup( $modelData ),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);

		$expected = [
			(object)[
				'oresc_rev' => 123,
				'oresc_class' => 1,
				'oresc_probability' => 0.45,
				'oresc_model' => 5,
			],
			(object)[
				'oresc_rev' => 123,
				'oresc_class' => 1,
				'oresc_probability' => 0.6,
				'oresc_model' => 6,
			]
		];
		$actual = iterator_to_array(
			$storage->getScores( 123, [ 'damaging', 'goodfaith' ] ),
			false
		);
		$this->assertEquals( $expected, $actual );
	}

}
