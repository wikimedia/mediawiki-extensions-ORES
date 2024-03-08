<?php

namespace ORES\Maintenance;

use Maintenance;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

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
		$groupConcat = $dbr->buildGroupConcatField(
			'|',
			[ 'OC' => 'ores_classification' ],
			'ores_classification.oresc_id',
			'OC.oresc_id = ores_classification.oresc_id'
		);
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
			$dbw->delete(
				'ores_classification',
				[ 'oresc_id' => $chunk ],
				__METHOD__
			);
			$this->waitForReplication();
		}

		$this->output( 'Done' );
	}

}

$maintClass = CleanDuplicateScores::class;
require_once RUN_MAINTENANCE_IF_MAIN;
