<?php

namespace ORES;

use Maintenance;
use MediaWiki\MediaWikiServices;

require_once getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php';

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
		$stats = MediaWikiServices::getInstance()->getService( 'ORESThresholdLookup' );

		foreach ( $models as $name => $info ) {
			$this->output( "\n$name\n" );
			$this->output( "\n" . print_r( $stats->getThresholds( $name, false ) ) . "\n" );
		}

		$this->output( "done.\n" );
	}

	/**
	 * Return a list of models available for this wiki.
	 * @return array
	 * @throws \RuntimeException
	 */
	protected function getModels() {
		$timestamp = \wfTimestampNow();
		$oresService = new ORESService();
		// Bypass the varnish cache
		$modelData = $oresService->request( [ $timestamp => true ] );
		if ( empty( $modelData['models'] ) ) {
			throw new \RuntimeException( 'Bad response from ORES when requesting models: '
				. json_encode( $modelData ) );
		}
		return $modelData['models'];
	}

}

$maintClass = DumpThresholds::class;
require_once RUN_MAINTENANCE_IF_MAIN;
