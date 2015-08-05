<?php

namespace ORES;

use Job;
use FormatJson;
use Title;
use MWHttpRequest;

class FetchScoreJob extends Job {
	/**
	 * @param Title $title
	 * @param array $params 'rcid' and 'revid' keys
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'ORESFetchScoreJob', $title, $params );
	}

	private function getUrl() {
		global $wgOresBaseUrl, $wgOresModels;

		$url = str_replace( '$wiki', wfWikiID(), $wgOresBaseUrl );
		$params = array(
			'models' => implode( '|', $wgOresModels ),
			'revids' => $this->params['revid'],
		);
		return wfAppendQuery( $url, $params );
	}

	public function run() {
		$url = $this->getUrl();
		$req = MWHttpRequest::factory( $url, null, __METHOD__ );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			wfDebugLog( 'ORES', "No response from ORES server at $url, "
				.  $status->getMessage()->text() );
			return false;
		}
		$json = $req->getContent();
		$wireData = FormatJson::decode( $json, true );
		if ( !$wireData || !empty( $wireData['error'] ) ) {
			wfDebugLog( 'ORES', 'Bad response from ORES server: ' . $json );
			return false;
		}

		// Map from wire format to database fields.
		$dbData = array();
		foreach ( $wireData as $revisionId => $revisionData ) {
			foreach ( $revisionData as $model => $modelOutputs ) {
				if ( isset( $modelOutputs['error'] ) ) {
					wfDebugLog( 'ORES', 'Model output an error: ' . $modelOutputs['error']['message'] );
					return false;
				}

				$prediction = $modelOutputs['prediction'];
				// Kludge out booleans so we can match prediction against class name.
				if ( $prediction === false ) {
					$prediction = 'false';
				} elseif ( $prediction === true ) {
					$prediction = 'true';
				}

				foreach ( $modelOutputs['probability'] as $class => $probability ) {
					$dbData[] = array(
						'ores_rev' => $revisionId,
						'ores_model' => $model,
						'ores_model_version' => '', // FIXME: waiting for API support
						'ores_class' => $class,
						'ores_probability' => $probability,
						'ores_is_predicted' => ( $prediction === $class ),
					);
				}
			}
		}

		wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData, __METHOD__ );
		return true;
	}
}
