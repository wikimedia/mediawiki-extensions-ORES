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
use Wikimedia\Rdbms\ILoadBalancer;

class SqlModelLookup implements ModelLookup {

	private $loadBalancer;

	private $modelData = null;

	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @see ModelLookup::getModelId()
	 * @param string $model
	 *
	 * @throws InvalidArgumentException
	 * @return int
	 */
	public function getModelId( $model ) {
		$modelData = $this->getModelData();
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
		$modelData = $this->getModelData();
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
		return $this->getModelData();
	}

	private function getModelData() {
		if ( $this->modelData === null || $this->modelData === [] ) {
			$this->modelData = [];
			$result = $this->loadBalancer->getConnection( DB_REPLICA )->select(
				'ores_model',
				[ 'oresm_id', 'oresm_name', 'oresm_version' ],
				[ 'oresm_is_current' => 1 ],
				__METHOD__
			);
			foreach ( $result as $row ) {
				$this->modelData[$row->oresm_name] = [
					'id' => (int)$row->oresm_id,
					'version' => $row->oresm_version
				];
			}
		}

		return $this->modelData;
	}

}
