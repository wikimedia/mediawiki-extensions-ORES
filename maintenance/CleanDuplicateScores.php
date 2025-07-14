<?php

namespace ORES\Maintenance;

use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * @ingroup Maintenance
 */
class CleanDuplicateScores extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ORES' );
		$this->addDescription( 'Clean up duplicate data in ORES scores' );
	}

	public function execute() {
		$dbr = $this->getReplicaDB();
		$dbw = $this->getPrimaryDB();
		$groupConcat = $dbr->newSelectQueryBuilder()
			->table( 'ores_classification', 'OC' )
			->field( 'ores_classification.oresc_id' )
			->where( 'OC.oresc_id = ores_classification.oresc_id' )
			->buildGroupConcatField( '|' );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_model', 'oresc_class', 'ids' => $groupConcat ] )
			->from( 'ores_classification' )
			->groupBy( [ 'oresc_rev', 'oresc_model', 'oresc_class' ] )
			->having( 'COUNT(*) > 1' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$ids = [];
		foreach ( $res as $row ) {
			$rowIds = explode( '|', $row->ids );
			if ( count( $rowIds ) > 1 ) {
				$newIds = array_slice( $rowIds, 1 );
				$ids = array_merge( $ids, $newIds );
			}
		}
		$c = count( $ids );
		$this->output( "Got $c duplicates, cleaning them." );
		$chunks = array_chunk( $ids, 1000 );
		foreach ( $chunks as $chunk ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'ores_classification' )
				->where( [ 'oresc_id' => $chunk ] )
				->caller( __METHOD__ )
				->execute();
			$this->waitForReplication();
		}

		$this->output( 'Done' );
	}

}

// @codeCoverageIgnoreStart
$maintClass = CleanDuplicateScores::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
