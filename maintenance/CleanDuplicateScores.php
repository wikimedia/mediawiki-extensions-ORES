<?php

namespace ORES\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

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
		$dbr = \wfGetDB( DB_REPLICA );
		$dbw = \wfGetDB( DB_PRIMARY );
		$groupConcat = $dbr->buildGroupConcatField(
			'|',
			'ores_classification AS OC',
			'ores_classification.oresc_id',
			'OC.oresc_id = ores_classification.oresc_id'
		);
		$res = $dbr->select(
			'ores_classification',
			[ 'oresc_rev', 'oresc_model', 'oresc_class' , 'ids' => $groupConcat ],
			'',
			__METHOD__,
			[ 'GROUP BY' => 'oresc_rev, oresc_model, oresc_class',
			'HAVING' => 'COUNT(*) > 1' ]
		);
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
			MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
		}

		$this->output( 'Done' );
	}

}

$maintClass = CleanDuplicateScores::class;
require_once RUN_MAINTENANCE_IF_MAIN;
