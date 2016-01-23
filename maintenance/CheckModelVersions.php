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
		$models = $this->getModels();

		foreach ( $models as $name => $info ) {
			wfGetDB( DB_MASTER )->replace( 'ores_model',
				'oresm_version',
				array(
					'oresm_name' => $name,
					'oresm_version' => $info['version'],
					'oresm_is_current' => 1,
				),
				__METHOD__
			);

			wfGetDB( DB_MASTER )->update( 'ores_model',
				array(
					'oresm_is_current' => 0,
				),
				array(
					'oresm_name' => $name,
					'oresm_version != ' . wfGetDb( DB_SLAVE )->addQuotes( $info['version'] ),
				),
				__METHOD__
			);
		}
	}

	/**
	 * Return a list of models available for this wiki.
	 */
	protected function getModels() {
		$modelData = Api::request();
		if ( empty( $modelData['models'] ) ) {
			throw new RuntimeException( 'Bad response from ORES when requesting models: '
				. json_encode( $modelData ) );
		}
		return $modelData['models'];
	}
}

$maintClass = 'ORES\CheckModelVersions';
require_once RUN_MAINTENANCE_IF_MAIN;
