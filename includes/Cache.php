<?php

namespace ORES;

use RuntimeException;

class Cache {
	/**
	 * Save scores to the database
	 *
	 * @param array $scores in the same structure as is returned by ORES.
	 * @param integer $rcid Recent changes ID
	 *
	 * @throws RuntimeException
	 */
	public function storeScores( $scores, $rcid ) {
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

				foreach ( $modelOutputs['probability'] as $class => $probability ) {
					$dbData[] = array(
						'ores_rc' => $rcid,
						'ores_model' => $model,
						'ores_model_version' => 0, // FIXME: waiting for API support
						'ores_class' => $class,
						'ores_probability' => $probability,
						'ores_is_predicted' => ( $prediction === $class ),
					);
				}
			}
		}

		wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData, __METHOD__ );
	}

	public function purge( $model, $version ) {
		wfGetDb( DB_MASTER )->delete( 'ores_classification',
			array(
				'ores_model' => $model,
				'ores_model_version' => $version,
			),
			__METHOD__
		);
	}

	public static function instance() {
		return new self();
	}
}
