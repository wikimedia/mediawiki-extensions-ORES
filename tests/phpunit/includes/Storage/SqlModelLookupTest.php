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

	const DAMAGING_OLD = 1;
	const REVERTED = 2;
	const DAMAGING = 3;

	/**
	 * @var SqlModelLookup
	 */
	protected $storage;

	protected function setUp() {
		parent::setUp();

		$this->tablesUsed[] = 'ores_model';
		self::insertModelData();
		$this->storage = new SqlModelLookup( MediaWikiServices::getInstance()->getDBLoadBalancer() );
	}

	public static function insertModelData() {
		$db = \wfGetDB( DB_MASTER );
		$dump = [
			[
				'oresm_id' => self::DAMAGING,
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.2',
				'oresm_is_current' => true
			],
			[
				'oresm_id' => self::REVERTED,
				'oresm_name' => 'reverted',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => true
			],
			[
				'oresm_id' => self::DAMAGING_OLD,
				'oresm_name' => 'damaging',
				'oresm_version' => '0.0.1',
				'oresm_is_current' => false
			],
		];

		$db->delete( 'ores_model', '*' );

		foreach ( $dump as $row ) {
			$db->insert( 'ores_model', $row );
		}
	}

	public function testGetModels() {
		$models = $this->storage->getModels();
		$expected = [
			'reverted' => [ 'id' => self::REVERTED, 'version' => '0.0.1' ],
			'damaging' => [ 'id' => self::DAMAGING, 'version' => '0.0.2' ]
		];
		$this->assertEquals( $expected, $models );
	}

	public function testGetModelId() {
		$this->assertEquals( self::REVERTED, $this->storage->getModelId( 'reverted' ) );
		$this->assertEquals( self::DAMAGING, $this->storage->getModelId( 'damaging' ) );
	}

	public function testGetInvalidModelId() {
		$this->setExpectedException( InvalidArgumentException::class );
		$this->storage->getModelId( 'foo' );
	}

	public function testGetModelVersion() {
		$this->assertEquals( '0.0.1', $this->storage->getModelVersion( 'reverted' ) );
		$this->assertEquals( '0.0.2', $this->storage->getModelVersion( 'damaging' ) );
	}

	public function testGetInvalidModelVersion() {
		$this->setExpectedException( InvalidArgumentException::class );
		$this->storage->getModelVersion( 'foo' );
	}

}
