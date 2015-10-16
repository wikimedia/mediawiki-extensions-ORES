<?php

namespace ORES;

use Job;
use Title;

class FetchScoreJob extends Job {
	/**
	 * @param Title $title
	 * @param array $params 'rcid' and 'revid' keys
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'ORESFetchScoreJob', $title, $params );
	}

	public function run() {
		$scores = Scoring::instance()->getScores( $this->params['revid'] );
		Cache::instance()->storeScores( $scores, $this->params['rcid'] );

		return true;
	}
}
