<?php

namespace ORES;

use Maintenance;

require_once ( getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php' );

/**
 * @ingroup Maintenance
 */
class DumpThresholds extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Display filtering levels and thresholds for enabled models.' );
	}

	public function execute() {
		$this->output( "Starting..." );
		$models = $this->getModels();
		$stats = Stats::newFromGlobalState();

		foreach ( $models as $name => $info ) {
			$this->output( "\n$name\n" );
			$this->output( "\n" . print_r( $stats->getThresholds( $name, false ) ) . "\n" );
		}

		$this->output( "done.\n" );
	}

	/**
	 * Return a list of models available for this wiki.
	 */
	protected function getModels() {
		$timestamp = \wfTimestampNow();
		$api = new Api();
		// Bypass the varnish cache
		$modelData = $api->request( [ $timestamp => true ] );
		if ( empty( $modelData['models'] ) ) {
			throw new \RuntimeException( 'Bad response from ORES when requesting models: '
				. json_encode( $modelData ) );
		}
		return $modelData['models'];
	}

}

$maintClass = 'ORES\DumpThresholds';
require_once RUN_MAINTENANCE_IF_MAIN;
