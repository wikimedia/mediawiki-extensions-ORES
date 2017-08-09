<?php

namespace ORES;

use MediaWiki\MediaWikiServices;
use RuntimeException;

class Cache {

	static protected $modelIds;

	protected $errorCallback;

	public function __construct() {
		$this->setErrorCallback( function ( $mssg, $revision ) {
			throw new RuntimeException( "Model contains an error for $revision: $mssg" );
		} );
	}

	/**
	 * Setter for $errorCallback
	 *
	 * @param callable $errorCallback the callback function
	 */
	public function setErrorCallback( $errorCallback ) {
		$this->errorCallback = $errorCallback;
	}

	/**
	 * Reduce score data so that it only includes revisions from a whitelist.
	 * @param array[] $scores in the same structure as is returned by ORES.
	 * @param int[] $acceptedRevids List of revision ids to accept.
	 * @return array[] Filtered scores.
	 */
	public function filterScores( array $scores, array $acceptedRevids ) {
		return array_intersect_key( $scores, array_flip( $acceptedRevids ) );
	}

	/**
	 * Save scores to the database
	 *
	 * @param array[] $scores in the same structure as is returned by ORES.
	 *
	 * @throws RuntimeException
	 */
	public function storeScores( array $scores ) {
		$dbData = [];
		foreach ( $scores as $revision => $revisionData ) {
			$this->processRevision( $dbData, $revision, $revisionData );
		}

		\wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData, __METHOD__, [ 'IGNORE' ] );
	}

	/**
	 * Delete cached scores
	 *
	 * Normally, we'll only delete scores from out-of-date model versions.
	 *
	 * @param string $model Model name.
	 * @param bool $isEverything When true, delete scores with the up-to-date
	 *   model version as well.  This can be used in cases where the old data is
	 *   bad, but no new model has been released yet.
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 */
	public function purge( $model, $isEverything, $batchSize = 1000 ) {
		$tables = [ 'ores_classification', 'ores_model' ];
		$join_conds = [
			'ores_model' => [ 'LEFT JOIN', 'oresm_id = oresc_model' ],
		];
		$conditions = [
			'oresm_name' => [ $model, null ],
		];
		if ( !$isEverything ) {
			$conditions[] = '(oresm_is_current != 1 OR oresm_is_current IS NULL)';
		}
		return $this->deleteRows( $tables, $conditions, $join_conds, $batchSize );
	}

	/**
	 * Delete old cached scores.
	 * A score is old of the corresponding revision is not in the recentchanges table.
	 * @param string $model Model name.
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 */
	public function purgeOld( $model, $batchSize = 1000 ) {
		$tables = [ 'ores_classification', 'ores_model', 'recentchanges' ];
		$join_conds = [
			'ores_model' => [ 'LEFT JOIN', 'oresm_id = oresc_model' ],
			'recentchanges' => [ 'LEFT JOIN', 'oresc_rev = rc_this_oldid' ],
		];
		$conditions = [
			'oresm_name' => [ $model, null ],
			'rc_this_oldid' => null,
		];
		return $this->deleteRows( $tables, $conditions, $join_conds, $batchSize );
	}

	/**
	 * Delete cached scores. Which rows to delete is given by Database::select parameters.
	 *
	 * @param array $tables
	 * @param array $conditions
	 * @param array $join_conds
	 * @param int $batchSize Maximum number of records to delete per loop.
	 *   Note that this function runs multiple batches, until all records are deleted.
	 * @return int The number of deleted rows
	 * @see Database::select
	 */
	protected function deleteRows( $tables, $conditions, $join_conds, $batchSize = 1000 ) {
		$dbr = \wfGetDB( DB_REPLICA );
		$dbw = \wfGetDB( DB_MASTER );

		$deletedRows = 0;

		do {
			$ids = $dbr->selectFieldValues( $tables,
				'oresc_id',
				$conditions,
				__METHOD__,
				[ 'LIMIT' => $batchSize ],
				$join_conds
			);
			if ( $ids ) {
				$dbw->delete( 'ores_classification',
					[ 'oresc_id' => $ids ],
					__METHOD__
				);
				$deletedRows += $dbw->affectedRows();
				MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->waitForReplication();
			}
		} while ( $ids );

		return $deletedRows;
	}

	/**
	 * @param string $model
	 *
	 * @throws RuntimeException
	 * @return string cached id of last seen version
	 */
	protected function getModelId( $model ) {
		if ( isset( self::$modelIds[$model] ) ) {
			return self::$modelIds[$model];
		}

		$modelId = \wfGetDB( DB_REPLICA )->selectField( 'ores_model',
			'oresm_id',
			[ 'oresm_name' => $model, 'oresm_is_current' => 1 ],
			__METHOD__
		);
		if ( $modelId === false ) {
			throw new RuntimeException( "No model available for [{$model}]" );
		}

		self::$modelIds[$model] = $modelId;
		return $modelId;
	}

	/**
	 * Convert data returned by Scoring::getScores() into ores_classification rows
	 *
	 * @note No row is generated for class 0
	 * @param array &$dbData Rows for insertion into ores_classification are added to this array
	 * @param int $revision Revision being processed
	 * @param array $revisionData Data returned by Scoring::getScores() for the revision.
	 *
	 * @throws RuntimeException
	 */
	public function processRevision( &$dbData, $revision, array $revisionData ) {
		global $wgOresModelClasses;
		// Map to database fields.

		foreach ( $revisionData as $model => $modelOutputs ) {
			if ( isset( $modelOutputs['error'] ) ) {
				call_user_func( $this->errorCallback, $modelOutputs['error']['message'], $revision );
				continue;
			}

			$prediction = $modelOutputs['prediction'];
			// Kludge out booleans so we can match prediction against class name.
			if ( $prediction === false ) {
				$prediction = 'false';
			} elseif ( $prediction === true ) {
				$prediction = 'true';
			}

			$modelId = $this->getModelId( $model );
			if ( !isset( $wgOresModelClasses[ $model ] ) ) {
				throw new RuntimeException( "Model $model is not configured" );
			}
			foreach ( $modelOutputs['probability'] as $class => $probability ) {
				$ores_is_predicted = $prediction === $class;
				if ( !isset( $wgOresModelClasses[ $model ][ $class ] ) ) {
					throw new RuntimeException( "Class $class in model $model is not configured" );
				}
				$class = $wgOresModelClasses[ $model ][ $class ];
				if ( $class === 0 ) {
					// We don't store rows for class 0, because we can compute the class 0 probability by
					// subtracting the sum of the probabilities of the other classes from 1
					continue;
				}
				$dbData[] = [
					'oresc_rev' => $revision,
					'oresc_model' => $modelId,
					'oresc_class' => $class,
					'oresc_probability' => $probability,
					'oresc_is_predicted' => ( $ores_is_predicted ),
				];
			}
		}
	}

	public function getModels() {
		$models = \wfGetDB( DB_REPLICA )->selectFieldValues( 'ores_model',
			'oresm_name',
			[],
			__METHOD__
		);
		return $models;
	}

	public static function instance() {
		return new self();
	}

}
