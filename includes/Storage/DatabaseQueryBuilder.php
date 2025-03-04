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

use ORES\Range;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;

class DatabaseQueryBuilder {

	private ThresholdLookup $thresholdLookup;
	private IReadableDatabase $db;

	public function __construct( ThresholdLookup $thresholdLookup, IReadableDatabase $db ) {
		$this->thresholdLookup = $thresholdLookup;
		$this->db = $db;
	}

	/**
	 * Build a WHERE clause for selecting only the ores_classification rows
	 * that match the specified classes for a model.
	 *
	 * @param string $modelName Model to query
	 * @param string|string[] $selected Array (or comma-separated string) of class names to select
	 * @param bool $isDiscrete
	 * 	True for a model with distinct rows for each class
	 * 	False for a model with thresholds within the score of a single row
	 * @return IExpression|false SQL Condition that can be used in WHERE directly, or false when there
	 * is nothing to filter on
	 */
	public function buildQuery( $modelName, $selected, $isDiscrete = false ) {
		return $isDiscrete ?
			$this->buildDiscreteQuery( $modelName, $selected ) :
			$this->buildRangeQuery( $modelName, $selected );
	}

	/**
	 * Build a WHERE clause for selecting only the ores_classification rows
	 * that match the specified classes for a model with thresholds.
	 *
	 * NOTE: This is used by PageTriage to filter on 'articlequality'
	 *
	 * @param string $modelName Model to filter
	 * @param string|string[] $selected Array (or comma-separated string) of class names to select
	 * @return IExpression|false SQL Condition that can be used in WHERE directly, or false when there
	 * is nothing to filter on
	 */
	private function buildRangeQuery( $modelName, $selected ) {
		$thresholds = $this->thresholdLookup->getThresholds( $modelName );
		$tableAlias = $this->makeOresClassificationTableAlias( $modelName );

		$selected = $this->validateSelected( $selected, array_keys( $thresholds ) );
		if ( !$selected ) {
			return false;
		}

		$ranges = [];
		foreach ( $selected as $className ) {
			$range = new Range(
				$thresholds[$className]['min'],
				$thresholds[$className]['max']
			);

			$result = array_filter(
				$ranges,
				static function ( Range $r ) use ( $range ) {
					return $r->overlaps( $range );
				}
			);
			$overlap = reset( $result );
			if ( $overlap ) {
				/** @var Range $overlap */
				$overlap->combineWith( $range );
			} else {
				$ranges[] = $range;
			}
		}

		$betweenConditions = array_map(
			function ( Range $range ) use ( $tableAlias ) {
				$min = $range->getMin();
				$max = $range->getMax();
				return $this->db->expr( "$tableAlias.oresc_probability", '>=', $min )
						->and( "$tableAlias.oresc_probability", '<=', $max );
			},
			$ranges
		);

		return $this->db->orExpr( $betweenConditions );
	}

	/**
	 * Build a WHERE clause for selecting only the ores_classification rows
	 * that match the specified levels for a model with discrete classification entries.
	 *
	 * NOTE: This is used by PageTriage to filter on 'draftquality'
	 *
	 * @param string $modelName Model to filter
	 * @param string|string[] $selected Array (or comma-separated string) of class names to select
	 * @return IExpression|false SQL Condition that can be used in WHERE directly, or false when there
	 * is nothing to filter on
	 */
	private function buildDiscreteQuery( $modelName, $selected ) {
		global $wgOresModelClasses;
		$modelClasses = $wgOresModelClasses[ $modelName ];
		$tableAlias = $this->makeOresClassificationTableAlias( $modelName );

		$selected = $this->validateSelected( $selected, array_keys( $modelClasses ) );
		if ( !$selected ) {
			return false;
		}

		$classIds = [];
		foreach ( $selected as $className ) {
			$classIds[] = $modelClasses[ $className ];
		}

		return $this->db->expr( "$tableAlias.oresc_is_predicted", '=', 1 )
			->and( "$tableAlias.oresc_class", '=', $classIds );
	}

	/**
	 * @param string $modelName
	 * @return string
	 */
	private function makeOresClassificationTableAlias( $modelName ) {
		return "ores_{$modelName}_cls";
	}

	/**
	 * @param string|string[] $selected Selected class names, can be a comma-separated
	 * @param string[] $possible All available class names for the model
	 * @return string[]|false Valid and unique selected class names or false if
	 *	no filters should be created
	 */
	private function validateSelected( $selected, $possible ) {
		$selected = is_array( $selected ) ? $selected :
			explode( ',', $selected );
		$selectedValid = array_intersect( $selected, $possible );
		$selectedValidUnique = array_unique( $selectedValid );

		if ( count( $selectedValidUnique ) === 0 ) {
			// none selected
			return false;
		}

		// all filters selected, and more than one possible filter exists.
		// For filters like e.g. revertrisklanguageagnostic, where only one threshold exists,
		// allow for selecting a single filter.
		if ( count( $selectedValidUnique ) === count( $possible ) && count( $possible ) > 1 ) {
			return false;
		}

		return $selectedValidUnique;
	}

}
