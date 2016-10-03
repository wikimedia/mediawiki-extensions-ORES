<?php

namespace ORES;

use Maintenance;

require_once ( getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php' );

/**
 * @ingroup Maintenance
 */
class CleanDuplicateScores extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Clean up duplicate data in ORES scores' );

	}

	public function execute() {
		$dbr = \wfGetDB( DB_REPLICA );
		$dbw = \wfGetDB( DB_MASTER );
		$res = $dbr->select(
			'ores_classification',
			[ 'oresc_id', 'oresc_rev', 'oresc_model', 'oresc_class' ],
			'',
			__METHOD__,
			[ 'GROUP BY' => 'oresc_rev, oresc_model, oresc_class',
			'HAVING' => 'COUNT(*) > 1' ]
		);
		$ids = [];
		$dump = [];
		foreach ( $row as $res ) {
			$key = implode( ',', [ $row->oresc_rev, $row->oresc_model, $row->oresc_class ] );
			if ( array_has_key( $key, $dump ) ) {
				$ids[] = $row->oresc_id;
			} else {
				$dump[] = $key;
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
			wfWaitForSlaves();
		}
		$this->output( "Done" );
	}
}

$maintClass = 'ORES\CleanDuplicateScores';
require_once RUN_MAINTENANCE_IF_MAIN;
