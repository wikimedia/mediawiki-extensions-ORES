<?php
namespace ORES;

use Job;
use Title;

class FetchScoreJob extends Job {
	/**
	 * @param Title $title
	 * @param array $params 'revid' key
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'ORESFetchScoreJob', $title, $params );
	}

	public function run() {
		$scores = Scoring::instance()->getScores( $this->params['revid'] );
		Cache::instance()->storeScores( $scores, $this->params['revid'] );

		// TODO: Or do we have to try/catch and return false on error, set the error string, etc?
		return true;
	}
}
