<?php

namespace ORES;

use FormatJson;
use MWHttpRequest;
use RuntimeException;

class Scoring {
	protected function getScoresUrl( $revisions, $models ) {
		global $wgOresBaseUrl;

		$url = $wgOresBaseUrl . 'scores/' . wfWikiID();
		$params = array(
			'models' => implode( '|', (array) $models ),
			'revids' => implode( '|', (array) $revisions ),
		);
		return wfAppendQuery( $url, $params );
	}

	/**
	 * @param integer|array $revisions Single or multiple revisions
	 * @param string|array|null $models Single or multiple model names.  If
	 * left empty, all configured models are queries.
	 * @return array Results in the form returned by ORES
	 * @throws RuntimeException
	 */
	public function getScores( $revisions, $models = null ) {
		if ( !$models ) {
			global $wgOresModels;
			$models = $wgOresModels;
		}
		$url = $this->getScoresUrl( $revisions, $models );
		$req = MWHttpRequest::factory( $url, null, __METHOD__ );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( "No response from ORES server at $url, "
				.  $status->getMessage()->text() );
		}
		$json = $req->getContent();
		$wireData = FormatJson::decode( $json, true );
		if ( !$wireData || !empty( $wireData['error'] ) ) {
			throw new RuntimeException( 'Bad response from ORES server: ' . $json );
		}

		return $wireData;
	}

	public static function instance() {
		return new self();
	}
}
