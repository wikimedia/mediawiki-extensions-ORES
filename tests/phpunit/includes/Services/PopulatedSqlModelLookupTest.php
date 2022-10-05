<?php

namespace ORES\Tests;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use ORES\ORESService;
use ORES\Services\PopulatedSqlModelLookup;
use ORES\Storage\HashModelLookup;
use ORES\Storage\SqlModelLookup;
use Psr\Log\NullLogger;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Services\PopulatedSqlModelLookup
 */
class PopulatedSqlModelLookupTest extends \MediaWikiIntegrationTestCase {

	/**
	 * @var SqlModelLookup
	 */
	protected $storageLookup;

	/**
	 * @var HashModelLookup
	 */
	protected $hashLookup;

	/**
	 * @var ORESService
	 */
	protected $oresServiceMock;

	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'ores_model';
		wfGetDB( DB_PRIMARY )->delete( 'ores_model', '*' );
		$this->storageLookup = new SqlModelLookup(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);

		$modelData = [
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 6, 'version' => '0.0.3' ],
		];
		$this->hashLookup = new HashModelLookup( $modelData );

		$this->setMwGlobals( [ 'wgOresWikiId' => 'testwiki' ] );
		$this->oresServiceMock = $this->createMock( ORESService::class );
		$res = [
			'testwiki' => [ 'models' => [
				'damaging' => [ 'version' => '0.4.0' ],
				'articlequality' => [ 'version' => '0.6.1' ]
			] ]
		];
		$this->oresServiceMock->method( 'request' )
			->willReturn( $res );
	}

	public function testGetModelsHash() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->hashLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$expected = [
			'damaging' => [ 'id' => 5, 'version' => '0.0.2' ],
			'goodfaith' => [ 'id' => 6, 'version' => '0.0.3' ],
		];
		$this->assertEquals( $expected, $populatedLookup->getModels() );
	}

	public function testGetModelIdHash() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->hashLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$this->assertEquals( 5, $populatedLookup->getModelId( 'damaging' ) );
		$this->assertEquals( 6, $populatedLookup->getModelId( 'goodfaith' ) );
	}

	public function testGetInvalidModelIdHash() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->hashLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$this->expectException( InvalidArgumentException::class );
		$populatedLookup->getModelId( 'foo' );
	}

	public function testGetModelVersionHash() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->hashLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$this->assertSame( '0.0.2', $populatedLookup->getModelVersion( 'damaging' ) );
	}

	public function testGetInvalidModelVersionHash() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->hashLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$this->expectException( InvalidArgumentException::class );
		$populatedLookup->getModelVersion( 'foo' );
	}

	public function testGetModelsSql() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->storageLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$expected = [
			'damaging' => [ 'version' => '0.4.0', 'id' => 5 ],
			'articlequality' => [ 'version' => '0.6.1', 'id' => 6 ]
		];
		$actual = $populatedLookup->getModels();
		// We have no control over id
		$actual['damaging']['id'] = 5;
		$actual['articlequality']['id'] = 6;
		$this->assertEquals( $expected, $actual );
	}

	public function testGetModelVersionSql() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->storageLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$this->assertSame( '0.4.0', $populatedLookup->getModelVersion( 'damaging' ) );
		$this->assertSame( '0.6.1', $populatedLookup->getModelVersion( 'articlequality' ) );
	}

	public function testGetInvalidModelVersionSql() {
		$populatedLookup = new PopulatedSqlModelLookup(
			$this->storageLookup,
			$this->oresServiceMock,
			new NullLogger()
		);
		$this->expectException( InvalidArgumentException::class );
		$populatedLookup->getModelVersion( 'foo' );
	}

	public function testGetInvalidResponseModelVersionSql() {
		$oresServiceMock = $this->createMock( ORESService::class );

		// "model" instead of "models"
		$res = [
			'testwiki' => [ 'model' => [ 'damaging' => [ 'version' => '0.4.0' ] ] ]
		];
		$oresServiceMock->method( 'request' )
			->willReturn( $res );

		$populatedLookup = new PopulatedSqlModelLookup(
			$this->storageLookup,
			$oresServiceMock,
			new NullLogger()
		);
		$this->expectException( InvalidArgumentException::class );
		$populatedLookup->getModelVersion( 'damaging' );
	}

}
