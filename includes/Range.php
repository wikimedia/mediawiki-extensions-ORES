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

namespace ORES;

/**
 * Represents a range defined by two values: min and max
 *
 * @package ORES
 */
class Range {

	/**
	 * @var float
	 */
	protected $min;

	/**
	 * @var float
	 */
	protected $max;

	/**
	 * @param float $min
	 * @param float $max
	 */
	public function __construct( $min, $max ) {
		if ( $min > $max ) {
			throw new \DomainException( '$min must be smaller than or equal to $max' );
		}
		$this->min = $min;
		$this->max = $max;
	}

	/**
	 * @return float
	 */
	public function getMin() {
		return $this->min;
	}

	/**
	 * @return float
	 */
	public function getMax() {
		return $this->max;
	}

	/**
	 * Check if the current range overlaps with or touches the given range.
	 *
	 * @param Range $other
	 * @return bool
	 */
	public function overlaps( Range $other ) {
		return max( $this->min, $other->min ) <= min( $this->max, $other->max );
	}

	/**
	 * Expands the current range to include the given range.
	 *
	 * @param Range $other
	 */
	public function combineWith( Range $other ) {
		$this->min = min( $this->min, $other->min );
		$this->max = max( $this->max, $other->max );
	}

}
