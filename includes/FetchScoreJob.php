<?php

namespace ORES;

use Job;
use MediaWiki\Logger\LoggerFactory;
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
		$logger = LoggerFactory::getInstance( 'ORES' );
		$logger->info( 'Fetching scores for revision ' . json_encode( $this->params ) );
		$scores = Scoring::instance()->getScores( $this->params['revid'] );
		Cache::instance()->storeScores( $scores );
		$logger->debug( 'Stored scores: ' . json_encode( $scores ) );

		// FIXME: Or should we return false on error, set the error string, etc?
		return true;
	}
}
