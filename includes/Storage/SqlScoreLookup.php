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

use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

class SqlScoreLookup implements StorageScoreLookup {

	private $modelLookup;

	private $loadBalancer;

	public function __construct(
		ModelLookup $modelLookup,
		ILoadBalancer $loadBalancer
	) {
		$this->modelLookup = $modelLookup;
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * Method to retrieve scores of given revision and models
	 *
	 * @param int|int[] $revisions Single or multiple revision IDs
	 * @param string|string[] $models Single or multiple model names.
	 *
	 * @return IResultWrapper
	 */
	public function getScores( $revisions, $models ) {
		$modelIds = array_map( [ $this->modelLookup, 'getModelId' ], $models );

		return $this->loadBalancer->getConnection( DB_REPLICA )->select(
			[ 'ores_classification' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ],
			[
				'oresc_rev' => $revisions,
				'oresc_model' => $modelIds,
			],
			__METHOD__
		);
	}

}
