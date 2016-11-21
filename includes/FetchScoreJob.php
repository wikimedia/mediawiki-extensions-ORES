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
		$expensive = is_array( $params['revid'] );

		if ( $expensive ) {
			sort( $params['revid'] );
		}

		parent::__construct( 'ORESFetchScoreJob', $title, $params );

		$this->removeDuplicates = $expensive;
	}

	public function run() {
		$logger = LoggerFactory::getInstance( 'ORES' );

		if ( $this->removeDuplicates ) {
			// Filter out revisions that already have scores by the time this
			// job runs.
			$revids = (array)$this->params['revid'];
			$dbr = \wfGetDB( DB_REPLICA );
			$revids = array_diff(
				$revids,
				$dbr->selectFieldValues(
					'ores_classification',
					'oresc_rev',
					[ 'oresc_rev' => $revids ],
					__METHOD__,
					[ 'DISTINCT' ]
				)
			);
			if ( !$revids ) {
				$logger->debug( 'Skipping fetch, no revisions need scores: ' . json_encode( $this->params ) );
				return true;
			}
			$this->params['revid'] = $revids;
		}

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
