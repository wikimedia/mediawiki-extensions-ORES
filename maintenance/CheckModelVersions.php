<?php

namespace ORES;

use Maintenance;

require_once ( getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: __DIR__ . '/../../../maintenance/Maintenance.php' );

/**
 * @ingroup Maintenance
 */
class CheckModelVersions extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Check available models and versions, and cache locally.' );
	}

	public function execute() {
		$this->output( "Starting..." );
		$models = $this->getModels();

		foreach ( $models as $name => $info ) {
			$dbw = \wfGetDB( DB_MASTER );
			$dbw->update( 'ores_model',
				[
					'oresm_is_current' => 0,
				],
				[
					'oresm_name' => $name,
					'oresm_version != ' . $dbw->addQuotes( $info['version'] ),
				],
				__METHOD__
			);

			$dbw->upsert( 'ores_model',
				[
					'oresm_name' => $name,
					'oresm_version' => $dbw->addQuotes( $info['version'] ),
					'oresm_is_current' => 1,
				],
				[ 'oresm_name', 'oresm_version' ],
				[
					'oresm_name' => $name,
					'oresm_version' => $dbw->addQuotes( $info['version'] ),
					'oresm_is_current' => 1,
				],
				__METHOD__
			);
		}

		$this->output( "done.\n" );
	}

	/**
	 * Return a list of models available for this wiki.
	 */
	protected function getModels() {
		$timestamp = \wfTimestampNow();
		// Bypass the varnish cache
		$modelData = Api::request( [ $timestamp => true ] );
		if ( empty( $modelData['models'] ) ) {
			throw new \RuntimeException( 'Bad response from ORES when requesting models: '
				. json_encode( $modelData ) );
		}
		return $modelData['models'];
	}

}

$maintClass = 'ORES\CheckModelVersions';
require_once RUN_MAINTENANCE_IF_MAIN;
