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
	}

	public function execute() {
		if ( $this->hasOption( 'model' ) ) {
			$models = [ $this->getOption( 'model' ) ];
		} else {
			$models = Cache::instance()->getModels();
		}

		foreach ( $models as $model ) {
			Cache::instance()->purge( $model, $this->hasOption( 'all' ) );
		}
		// @todo this script needs some output
	}
}

$maintClass = 'ORES\PurgeScoreCache';
require_once RUN_MAINTENANCE_IF_MAIN;
