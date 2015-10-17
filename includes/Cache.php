<?php
namespace ORES;

use RuntimeException;

class Cache {
	static protected $modelVersions;

	/**
	 * Save scores to the database
	 *
	 * @param array $scores in the same structure as is returned by ORES.
	 * @param integer $revid Revision ID
	 *
	 * @throws RuntimeException
	 */
	public function storeScores( $scores, $revid ) {
		// Map to database fields.
		$dbData = array();
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

				$modelVersion = $this->getModelVersion( $model );

				foreach ( $modelOutputs['probability'] as $class => $probability ) {
					$dbData[] = array(
						'ores_rev' => $revid,
						'ores_model' => $model,
						'ores_model_version' => $modelVersion,
						'ores_class' => $class,
						'ores_probability' => $probability,
						'ores_is_predicted' => ( $prediction === $class ),
					);
				}
			}
		}

		wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData, __METHOD__ );
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
		$dbr = wfGetDb( DB_SLAVE );
		$dbw = wfGetDb( DB_MASTER );

		$conditions = array(
			'ores_model' => $model,
		);
		if ( !$isEverything ) {
			$currentModelVersion = $this->getModelVersion( $model );
			$conditions[] = 'ores_model_version != ' . $dbr->addQuotes( $currentModelVersion );
		}

		do {
			$ids = $dbr->selectFieldValues( 'ores_classification',
				'ores_rev',
				$conditions,
				__METHOD__,
				array( 'LIMIT' => $batchSize )
			);
			if ( $ids ) {
				$dbw->delete( 'ores_classification',
					array( 'ores_rev' => $ids ),
					__METHOD__
				);
				wfWaitForSlaves();
			}
		} while ( $ids );
	}

	/**
	 * @param string $model
	 *
	 * @return string cached last seen version
	 */
	protected function getModelVersion( $model ) {
		if ( isset( self::$modelVersions[$model] ) ) {
			return self::$modelVersions[$model];
		}

		$modelVersion = wfGetDb( DB_SLAVE )->selectField( 'ores_model',
			'ores_model_version',
			array( 'ores_model' => $model ),
			__METHOD__
		);
		if ( $modelVersion === false ) {
			throw new RuntimeException( "No model version available for [{$model}]" );
		}

		self::$modelVersions[$model] = $modelVersion;
		return $modelVersion;
	}

	public function getModels() {
		$models = wfGetDb( DB_SLAVE )->selectFieldValues( 'ores_model',
			'ores_model',
			array(),
			__METHOD__
		);
		return $models;
	}

	public static function instance() {
		return new self();
	}
}
