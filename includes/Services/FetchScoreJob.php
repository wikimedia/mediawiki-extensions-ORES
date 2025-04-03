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

use Job;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use ORES\Hooks\HookRunner;
use RuntimeException;

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

	/** @inheritDoc */
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
			$message = "Service failed to respond properly: $mssg";
			$this->setLastError( $message );
			$logger->warning( "$message\n" );
			return false;
		}

		$success = true;
		ORESServices::getScoreStorage()->storeScores(
			$scores,
			function ( $mssg, $revision ) use ( &$success, $logger ) {
				$message = "ScoreFetcher errored for $revision: $mssg";
				$logger->warning( "$message\n" );
				if ( $mssg !== 'RevisionNotFound' ) {
					$success = false;
					$this->setLastError( $message );
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

	private function findDuplicates(): array {
		$revids = (array)$this->params['revid'];
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$revids = array_diff(
			$revids,
			$dbr->newSelectQueryBuilder()
				->select( 'oresc_rev' )
				->distinct()
				->from( 'ores_classification' )
				->where( [ 'oresc_rev' => $revids ] )
				->caller( __METHOD__ )
				->fetchFieldValues()
		);

		return $revids;
	}

	private function getCleanupModels(): array {
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
		$services = MediaWikiServices::getInstance();
		$revisionStore = $services->getRevisionStore();
		$hookRunner = new HookRunner( $services->getHookContainer() );
		foreach ( $revids as $revid ) {
			if ( !isset( $scores[$revid] ) ) {
				continue;
			}

			$revision = $revisionStore->getRevisionById( (int)$revid );
			if ( $revision === null ) {
				continue;
			}

			$hookRunner->onORESRecentChangeScoreSavedHook( $revision, $scores );
		}
	}

}
