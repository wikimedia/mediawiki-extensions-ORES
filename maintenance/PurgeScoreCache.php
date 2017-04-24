<?php

namespace ORES;

use Maintenance;

require_once ( getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php' );

/**
 * @ingroup Maintenance
 */
class PurgeScoreCache extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Purge out of date (or all) ORES model results' );

		$this->addOption( 'model', 'Model name (optional)', false, true );
		$this->addOption( 'all', 'Flag to indicate that we want to clear all data, ' .
			'even those from the most recent model', false, false );
		$this->addOption( 'old', 'Flag to indicate that we only want to clear old data ' .
			'that is not in recent changes anymore. Implicitly assumes --all.', false, false );
	}

	public function execute() {
		if ( $this->hasOption( 'model' ) ) {
			$models = [ $this->getOption( 'model' ) ];
		} else {
			$models = Cache::instance()->getModels();
		}

		$this->output( "Purging ORES scores:\n" );
		foreach ( $models as $model ) {
			if ( $this->hasOption( 'old' ) ) {
				$deletedRows = Cache::instance()->purgeOld( $model );
				$description = 'old rows';
			} elseif ( $this->hasOption( 'all' ) ) {
				$deletedRows = Cache::instance()->purge( $model, true );
				$description = 'old model versions';
			} else {
				$deletedRows = Cache::instance()->purge( $model, false );
				$description = 'all rows';
			}
			if ( $deletedRows ) {
				$this->output( "   ...purging $description from '$model' model': deleted $deletedRows rows\n" );
			} else {
				$this->output( "   ...skipping '$model' model, no action needed\n" );
			}
		}
		$this->output( "   done.\n" );
	}

}

$maintClass = 'ORES\PurgeScoreCache';
require_once RUN_MAINTENANCE_IF_MAIN;
