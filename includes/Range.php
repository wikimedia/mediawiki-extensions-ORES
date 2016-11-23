<?php

namespace ORES;

/**
 * Represents a range defined by two values: min and max
 *
 * Class Range
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
	 * Range constructor.
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
		return max( $this->getMin(), $other->getMin() ) <= min( $this->getMax(), $other->getMax() );
	}

	/**
	 * Expands the current range to include the given range.
	 *
	 * @param Range $other
	 */
	public function combineWith( Range $other ) {
		$this->min = min( $this->getMin(), $other->getMin() );
		$this->max = max( $this->getMax(), $other->getMax() );
	}

}
