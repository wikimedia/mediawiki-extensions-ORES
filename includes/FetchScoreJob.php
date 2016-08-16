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
		$scores = Scoring::instance()->getScores(
			$this->params['revid'], null, $this->params['extra_params'] );
		$cache = Cache::instance();
		$success = true;
		$cache->setErrorCallback( function ( $mssg, $revision ) use ( &$success, $logger ) {
			$logger->warning( "Scoring errored for $revision: $mssg\n" );
			$success = false;
		} );
		$cache->storeScores( $scores );
		if ( $success === true ) {
			$logger->debug( 'Stored scores: ' . json_encode( $scores ) );
		}
		return $success;
	}
}
