<?php

require_once ( getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php'
	: dirname( __FILE__ ) . '/../../../maintenance/Maintenance.php' );

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
				'ores_model',
				array(
					'ores_model' => $name,
					'ores_model_version' => $info['version'],
				),
				__METHOD__
			);
		}
	}

	protected function getModels() {
		global $wgOresBaseUrl;

		$url = $wgOresBaseUrl . 'scores/' . wfWikiID() . '/';
		$req = MWHttpRequest::factory( $url, null, __METHOD__ );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( "Failed to get revscoring models [{$url}], "
				. $status->getMessage()->text() );
		}
		$json = $req->getContent();
		$modelData = FormatJson::decode( $json, true );
		if ( !$modelData || !empty( $modelData['error'] ) || empty( $modelData['models'] ) ) {
			throw new RuntimeException( "Bad response from revscoring models request [{$url}]: {$json}" );
		}
		return $modelData['models'];
	}
}

$maintClass = 'CheckModelVersions';
require_once RUN_MAINTENANCE_IF_MAIN;
