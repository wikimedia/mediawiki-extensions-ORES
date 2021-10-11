<?php

namespace ORES\Tests;

use InvalidArgumentException;
use MediaWikiIntegrationTestCase;
use ORES\Storage\HashModelLookup;

/**
 * @group ORES
 * @covers ORES\Storage\HashModelLookup
 */
class HashModelLookupTest extends MediaWikiIntegrationTestCase {

	/**
	 * @var HashModelLookup
	 */
	protected $storage;

	protected function setUp(): void {
		parent::setUp();

		$modelData = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 3, 'version' => '0.0.2' ],
		];
		$this->storage = new HashModelLookup( $modelData );
	}

	/**
	 * @covers ORES\Storage\HashModelLookup::getModels
	 */
	public function testGetModels() {
		$models = $this->storage->getModels();
		$expected = [
			'reverted' => [ 'id' => 2, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => 3, 'version' => '0.0.2' ]
		];
		$this->assertEquals( $expected, $models );
	}

	/**
	 * @covers ORES\Storage\HashModelLookup::getModelId
	 */
	public function testGetModelId() {
		$this->assertEquals( 2, $this->storage->getModelId( 'reverted' ) );
		$this->assertEquals( 3, $this->storage->getModelId( 'damaging' ) );
	}

	/**
	 * @covers ORES\Storage\HashModelLookup::getModelId
	 */
	public function testGetInvalidModelId() {
		$this->expectException( InvalidArgumentException::class );
		$this->storage->getModelId( 'foo' );
	}

	/**
	 * @covers ORES\Storage\HashModelLookup::getModelVersion
	 */
	public function testGetModelVersion() {
		$this->assertSame( '0.0.1', $this->storage->getModelVersion( 'reverted' ) );
		$this->assertSame( '0.0.2', $this->storage->getModelVersion( 'damaging' ) );
	}

	/**
	 * @covers ORES\Storage\HashModelLookup::getModelVersion
	 */
	public function testGetInvalidModelVersion() {
		$this->expectException( InvalidArgumentException::class );
		$this->storage->getModelVersion( 'foo' );
	}

}
