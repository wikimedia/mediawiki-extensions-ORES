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

class HashModelLookup implements ModelLookup {

	/** @var array[] */
	private $modelData;

	public function __construct( array $modelData ) {
		$this->modelData = $modelData;
	}

	/**
	 * @see ModelLookup::getModelId()
	 * @param string $model
	 *
	 * @throws InvalidArgumentException
	 * @return int
	 */
	public function getModelId( $model ) {
		$modelData = $this->modelData;
		if ( !array_key_exists( $model, $modelData ) ) {
			throw new InvalidArgumentException( "No model available for [{$model}]" );
		}

		return $modelData[$model]['id'];
	}

	/**
	 * @see ModelLookup::getModelVersion()
	 * @param string $model
	 *
	 * @throws InvalidArgumentException
	 * @return string
	 */
	public function getModelVersion( $model ) {
		$modelData = $this->modelData;
		if ( !array_key_exists( $model, $modelData ) ) {
			throw new InvalidArgumentException( "No model available for [{$model}]" );
		}

		return $modelData[$model]['version'];
	}

	/**
	 * @see ModelLookup::getModels()
	 *
	 * @return array[]
	 */
	public function getModels() {
		return $this->modelData;
	}

}
