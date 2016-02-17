<?php

namespace ORES;

use RuntimeException;

class Cache {
	static protected $modelIds;
	protected $classMap;

	public function __construct() {
		$this->classMap = [ 'true' => 1, 'false' => 0,
			'B' => 0, 'C' => 1, 'FA' => 2, 'GA' => 3,
			'Start' => 4, 'Stub' => 5 ];
	}
	/**
	 * Save scores to the database
	 *
	 * @param array $scores in the same structure as is returned by ORES.
	 *
	 * @throws RuntimeException
	 */
	public function storeScores( $scores ) {
		// Map to database fields.
		$dbData = [];
		foreach ( $scores as $revision => $revisionData ) {
			foreach ( $revisionData as $model => $modelOutputs ) {
				if ( isset( $modelOutputs['error'] ) ) {
					throw new RuntimeException( 'Model contains an error: ' . $modelOutputs['error']['message'] );
				}

				$prediction = $modelOutputs['prediction'];
				// Kludge out booleans so we can match prediction against class name.
				if ( $prediction === false ) {
					$prediction = 'false';
				} elseif ( $prediction === true ) {
					$prediction = 'true';
				}

				$modelId = $this->getModelId( $model );
				foreach ( $modelOutputs['probability'] as $class => $probability ) {
					$ores_is_predicted = $prediction === $class;
					$class = $this->classMap[$class];
					if ( $class === 0 ) {
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

		\wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData, __METHOD__ );
	}

	/**
	 * Delete cached scores
	 *
	 * Normally, we'll only delete scores from out-of-date model versions.
	 *
	 * @param string $model Model name.
	 * @param bool $isEverything When true, delete scores with the up-to-date
	 * model version as well.  This can be used in cases where the old data is
	 * bad, but no new model has been released yet.
	 * @param integer $batchSize Maximum number of records to delete per loop.
	 * Note that this function runs multiple batches, until all records are deleted.
	 */
	public function purge( $model, $isEverything, $batchSize = 1000 ) {
		$dbr = \wfGetDB( DB_SLAVE );
		$dbw = \wfGetDB( DB_MASTER );

		$join_conds = [ 'ores_model' =>
			[ 'LEFT JOIN', 'oresm_id = oresc_model' ] ];
		$conditions = [
			'oresm_name' => $model,
		];
		if ( !$isEverything ) {
			$conditions[] = 'oresm_is_current != 1';
		}

		do {
			$ids = $dbr->selectFieldValues( 'ores_classification',
				'oresc_rev',
				$conditions,
				__METHOD__,
				[ 'LIMIT' => $batchSize ],
				$join_conds
			);
			if ( $ids ) {
				$dbw->delete( 'ores_classification',
					[ 'oresc_rev' => $ids ],
					__METHOD__
				);
				wfWaitForSlaves();
			}
		} while ( $ids );
	}

	/**
	 * @param string $model
	 *
	 * @return string cached id of last seen version
	 */
	protected function getModelId( $model ) {
		if ( isset( self::$modelIds[$model] ) ) {
			return self::$modelIds[$model];
		}

		$modelId = \wfGetDB( DB_SLAVE )->selectField( 'ores_model',
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

	public function getModels() {
		$models = \wfGetDB( DB_SLAVE )->selectFieldValues( 'ores_model',
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
