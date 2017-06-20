<?php

namespace ORES;

use WebRequest;

class Scoring {
	/** @var WebRequest|string[]|null */
	private $originalRequest;

	/**
	 * @param integer|array $revisions Single or multiple revisions
	 * @param string|array|null $models Single or multiple model names.  If
	 * left empty, all configured models are queries.
	 * @param array $extra_params to be passed to ORES endpoint
	 *
	 * @return array Results in the form returned by ORES
	 * @throws \RuntimeException
	 */
	public function getScores( $revisions, $models = null, array $extra_params = [] ) {
		if ( !$models ) {
			global $wgOresModels;
			$models = array_keys( array_filter( $wgOresModels ) );
		}

		$params = [
			'models' => implode( '|', (array)$models ),
			'revids' => implode( '|', (array)$revisions ),
		];

		if ( $this->originalRequest === null ) {
			$api = Api::newFromContext();
		} else {
			$api = new Api();
			$api->setOriginalRequest( $this->originalRequest );
		}
		$wireData = $api->request( array_merge( $params, $extra_params ) );
		return $wireData;
	}

	/**
	 * @param WebRequest|string[] $originalRequest See MwHttpRequest::setOriginalRequest()
	 */
	public function setOriginalRequest( $originalRequest ) {
		$this->originalRequest = $originalRequest;
	}

	public static function instance() {
		return new self();
	}

}
