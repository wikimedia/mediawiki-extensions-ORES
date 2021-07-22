<?php

namespace ORES\Tests;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWikiLangTestCase;
use ORES\Storage\SqlModelLookup;

/**
 * @group ORES
 * @group Database
 * @covers ORES\Storage\SqlModelLookup
 */
class SqlModelLookupTest extends MediaWikiLangTestCase {

	/**
	 * @var SqlModelLookup
	 */
	protected $storage;

	protected function setUp(): void {
		parent::setUp();

		$this->tablesUsed[] = 'ores_model';
		TestHelper::insertModelData();
		$this->storage = new SqlModelLookup( MediaWikiServices::getInstance()->getDBLoadBalancer() );
	}

	public function testGetModels() {
		$models = $this->storage->getModels();
		$expected = [
			'reverted' => [ 'id' => TestHelper::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => TestHelper::DAMAGING, 'version' => '0.0.2' ]
		];
		$this->assertEquals( $expected, $models );
	}

	public function testGetModelId() {
		$this->assertEquals( TestHelper::REVERTED, $this->storage->getModelId( 'reverted' ) );
		$this->assertEquals( TestHelper::DAMAGING, $this->storage->getModelId( 'damaging' ) );
	}

	public function testGetInvalidModelId() {
		$this->expectException( InvalidArgumentException::class );
		$this->storage->getModelId( 'foo' );
	}

	public function testGetModelVersion() {
		$this->assertSame( '0.0.1', $this->storage->getModelVersion( 'reverted' ) );
		$this->assertSame( '0.0.2', $this->storage->getModelVersion( 'damaging' ) );
	}

	public function testGetInvalidModelVersion() {
		$this->expectException( InvalidArgumentException::class );
		$this->storage->getModelVersion( 'foo' );
	}

}
