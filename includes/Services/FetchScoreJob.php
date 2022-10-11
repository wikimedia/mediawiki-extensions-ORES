<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace ORES\Services;

use Hooks;
use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use RuntimeException;
use Title;

class FetchScoreJob extends Job {

	/**
	 * @var ScoreFetcher
	 */
	private $scoreFetcher;

	/**
	 * @param Title $title
	 * @param array $params
	 *   - 'revid': (int|int[]) revision IDs for which to fetch the score
	 *   - 'originalRequest': (string[]) request data to forward to the upstream API;
	 *       see MwHttpRequest::setOriginalRequest()
	 */
	public function __construct( Title $title, array $params ) {
		$expensive = is_array( $params['revid'] );

		if ( $expensive ) {
			sort( $params['revid'] );
		}

		parent::__construct( 'ORESFetchScoreJob', $title, $params );

		$this->removeDuplicates = $expensive;
		$this->scoreFetcher = ScoreFetcher::instance();
	}

	/**
	 * ScoreFetcher service override for testing
	 *
	 * @param ScoreFetcher $scoreFetcher
	 */
	public function setScoreFetcher( ScoreFetcher $scoreFetcher ) {
		$this->scoreFetcher = $scoreFetcher;
	}

	public function run() {
		$logger = LoggerFactory::getInstance( 'ORES' );

		if ( $this->removeDuplicates ) {
			$revids = $this->findDuplicates();
			if ( !$revids ) {
				$logger->debug( 'Skipping fetch, no revisions need scores: ' . json_encode( $this->params ) );
				return true;
			}
			$this->params['revid'] = $revids;
		}

		$logger->info( 'Fetching scores for revision ' . json_encode( $this->params ) );

		try {
			$scores = $this->scoreFetcher->getScores(
				$this->params['revid'],
				$this->params['models'] ?? null,
				$this->params['precache'],
				$this->params['originalRequest'] ?? null
			);
		} catch ( RuntimeException $exception ) {
			$mssg = $exception->getMessage();
			$logger->warning( "Service failed to respond properly: $mssg\n" );
			return false;
		}

		$success = true;
		ORESServices::getScoreStorage()->storeScores(
			$scores,
			static function ( $mssg, $revision ) use ( &$success, $logger ) {
				$logger->warning( "ScoreFetcher errored for $revision: $mssg\n" );
				if ( $mssg !== 'RevisionNotFound' ) {
					$success = false;
				}
			},
			$this->getCleanupModels()
		);
		if ( $success === true ) {
			$logger->debug( 'Stored scores: ' . json_encode( $scores ) );
		}

		$this->fireORESRecentChangeScoreSavedHook( (array)$this->params['revid'], $scores );

		return $success;
	}

	private function findDuplicates() {
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

		return $revids;
	}

	private function getCleanupModels() {
		global $wgOresModels;
		$models = [];
		foreach ( $wgOresModels as $modelName => $model ) {
			if ( !isset( $model['enabled'] ) || !$model['enabled'] ) {
				continue;
			}

			if ( !isset( $model['cleanParent'] ) || !$model['cleanParent'] ) {
				continue;
			}

			$models[] = $modelName;
		}

		return $models;
	}

	private function fireORESRecentChangeScoreSavedHook( array $revids, array $scores ) {
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		foreach ( $revids as $revid ) {
			if ( !isset( $scores[$revid] ) ) {
				continue;
			}

			$revision = $revisionStore->getRevisionById( (int)$revid );
			if ( $revision === null ) {
				continue;
			}

			Hooks::run( 'ORESRecentChangeScoreSavedHook', [ $revision, $scores ] );
		}
	}

}
