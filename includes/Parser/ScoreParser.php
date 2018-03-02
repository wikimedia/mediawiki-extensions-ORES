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

namespace ORES\Parser;

use InvalidArgumentException;
use ORES\Storage\ModelLookup;
use RuntimeException;

/**
 * Class for parsing ORES service score response
 *
 * @license GPL-3.0-or-later
 */
class ScoreParser {

	private $modelLookup;

	private $modelClasses;

	public function __construct( ModelLookup $modelLookup, array $modelClasses ) {
		$this->modelLookup = $modelLookup;
		$this->modelClasses = $modelClasses;
	}

	/**
	 * Convert data returned by ScoreFetcher::getScores() into ores_classification rows
	 *
	 * @note No row is generated for class 0
	 * @param int $revision Revision being processed
	 * @param array $revisionData Data returned by ScoreFetcher::getScores() for the revision.
	 *
	 * @return array[]
	 * @throws RuntimeException
	 */
	public function processRevision( $revision, array $revisionData ) {
		$dbData = [];
		foreach ( $revisionData as $model => $modelOutputs ) {
			if ( isset( $modelOutputs['error'] ) ) {
				throw new InvalidArgumentException( $modelOutputs['error']['message'] );
			}

			$dbData = array_merge(
				$dbData,
				$this->processRevisionPerModel( $revision, $model, $modelOutputs )
			);
		}

		return $dbData;
	}

	/**
	 * @param int $revision
	 * @param string $model
	 * @param array[] $modelOutputs
	 *
	 * @return array
	 */
	private function processRevisionPerModel( $revision, $model, array $modelOutputs ) {
		$processedData = [];
		$prediction = $modelOutputs['score']['prediction'];
		// Kludge out booleans so we can match prediction against class name.
		if ( $prediction === false ) {
			$prediction = 'false';
		} elseif ( $prediction === true ) {
			$prediction = 'true';
		}

		$modelId = $this->modelLookup->getModelId( $model );
		if ( !isset( $this->modelClasses[$model] ) ) {
			throw new InvalidArgumentException( "Model $model is not configured" );
		}
		foreach ( $modelOutputs['score']['probability'] as $class => $probability ) {
			$ores_is_predicted = $prediction === $class;
			if ( !isset( $this->modelClasses[$model][$class] ) ) {
				throw new InvalidArgumentException( "Class $class in model $model is not configured" );
			}
			$class = $this->modelClasses[$model][$class];
			if ( $class === 0 ) {
				// We don't store rows for class 0, because we can compute the class 0 probability by
				// subtracting the sum of the probabilities of the other classes from 1
				continue;
			}
			$processedData[] = [
				'oresc_rev' => $revision,
				'oresc_model' => $modelId,
				'oresc_class' => $class,
				'oresc_probability' => $probability,
				'oresc_is_predicted' => ( $ores_is_predicted ),
			];
		}

		return $processedData;
	}

}
