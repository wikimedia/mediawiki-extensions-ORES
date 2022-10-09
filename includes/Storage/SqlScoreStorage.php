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

namespace ORES\Storage;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\ILoadBalancer;

class SqlScoreStorage implements ScoreStorage {

	/**
	 * @var ILoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @var ModelLookup
	 */
	private $modelLookup;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	public function __construct(
		ILoadBalancer $loadBalancer,
		ModelLookup $modelLookup,
		LoggerInterface $logger
	) {
		$this->loadBalancer = $loadBalancer;
		$this->modelLookup = $modelLookup;
		$this->logger = $logger;
	}

	/**
	 * @see ModelLookup::getModelId()
	 *
	 * @param array[] $scores
	 * @param callable|null $errorCallback
	 * @param string[] $modelsToClean
	 */
	public function storeScores(
		$scores,
		callable $errorCallback = null,
		array $modelsToClean = []
	) {
		// TODO: Make $wgOresModelClasses an argument and deprecate the whole config variable
		global $wgOresModelClasses, $wgOresAggregatedModels;

		if ( $errorCallback === null ) {
			/**
			 * @param string $mssg
			 * @param int $revision
			 * @return never
			 */
			$errorCallback = static function ( $mssg, $revision ) {
				throw new RuntimeException( "Model contains an error for $revision: $mssg" );
			};
		}

		$dbData = [];

		$scoreParser = new ScoreParser(
			$this->modelLookup,
			$wgOresModelClasses,
			$wgOresAggregatedModels
		);
		foreach ( $scores as $revision => $revisionData ) {
			try {
				$dbDataPerRevision = $scoreParser->processRevision( $revision, $revisionData );
			} catch ( InvalidArgumentException $exception ) {
				call_user_func( $errorCallback, $exception->getMessage(), $revision );
				continue;
			}

			$dbData = array_merge( $dbData, $dbDataPerRevision );
		}

		try {
			$this->loadBalancer->getConnection( DB_PRIMARY )->insert(
				'ores_classification',
				$dbData,
				__METHOD__,
				[ 'IGNORE' ]
			);
		} catch ( DBError $exception ) {
			$this->logger->error(
				'Inserting new data into the datbase has failed:' . $exception->getMessage()
			);
			return;
		}

		if ( $modelsToClean !== [] ) {
			$this->cleanUpOldScores( $scores, $modelsToClean );
		}
	}

	/**
	 * @see ScoreStorage::purgeRows()
	 *
	 * @param int[] $revIds array of revision ids to clean scores
	 */
	public function purgeRows( array $revIds ) {
		global $wgOresModels;
		$modelsToKeep = [];
		foreach ( $wgOresModels as $model => $modelData ) {
			$modelId = $this->checkModelToKeep( $model, $modelData );
			if ( $modelId !== false ) {
				$modelsToKeep[] = $modelId;
			}
		}

		$conditions = [ 'oresc_rev' => $revIds ];
		if ( !empty( $modelsToKeep ) ) {
			$conditions[] = 'oresc_model NOT IN (' . implode( ', ', $modelsToKeep ) . ')';
		}

		$this->loadBalancer->getConnection( DB_PRIMARY )->delete(
			'ores_classification',
			$conditions,
			__METHOD__
		);
	}

	/**
	 * @param string $model name
	 * @param array $modelData
	 * @return int|bool model id to keep, false otherwise
	 */
	private function checkModelToKeep( $model, array $modelData ) {
		if ( !isset( $modelData['enabled'] ) || !$modelData['enabled'] ) {
			return false;
		}
		if ( !isset( $modelData['keepForever'] ) || !$modelData['keepForever'] ) {
			return false;
		}

		try {
			$modelId = $this->modelLookup->getModelId( $model );
		} catch ( InvalidArgumentException $exception ) {
			$this->logger->warning( "Model {$model} can't be found in the model lookup" );
			return false;
		}

		return $modelId;
	}

	/**
	 * @param array[] $scores
	 * @param string[] $modelsToClean
	 */
	private function cleanUpOldScores( array $scores, array $modelsToClean ) {
		$modelIds = [];
		foreach ( $modelsToClean as $model ) {
			$modelIds[] = $this->modelLookup->getModelId( $model );
		}

		$newRevisions = array_keys( $scores );

		$parentIds = $this->loadBalancer->getConnection( DB_REPLICA )->selectFieldValues(
			'recentchanges',
			'rc_last_oldid',
			[ 'rc_this_oldid' => $newRevisions ],
			__METHOD__
		);

		if ( $parentIds ) {
			$this->loadBalancer->getConnection( DB_PRIMARY )->delete(
				'ores_classification',
				[ 'oresc_rev' => $parentIds, 'oresc_model' => $modelIds ],
				__METHOD__
			);
		}
	}

}
