<?php

namespace ORES;

class Scoring {
	/**
	 * @param integer|array $revisions Single or multiple revisions
	 * @param string|array|null $models Single or multiple model names.  If
	 * left empty, all configured models are queries.
	 * @param array $params extra params to be passed to ORES endpoint
	 * @return array Results in the form returned by ORES
	 * @throws \RuntimeException
	 */
	public function getScores( $revisions, $models = null, array $extra_params = [] ) {
		if ( !$models ) {
			global $wgOresModels;
			$models = array_keys( array_filter( $wgOresModels ) );
		}

		$params = [
			'models' => implode( '|', (array) $models ),
			'revids' => implode( '|', (array) $revisions ),
		];

		$wireData = Api::request( array_merge( $params, $extra_params ) );
		return $wireData;
	}

	public static function instance() {
		return new self();
	}
}
