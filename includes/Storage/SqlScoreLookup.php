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

use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;

class SqlScoreLookup implements StorageScoreLookup {

	private ModelLookup $modelLookup;
	private IConnectionProvider $dbProvider;

	public function __construct(
		ModelLookup $modelLookup,
		IConnectionProvider $dbProvider
	) {
		$this->modelLookup = $modelLookup;
		$this->dbProvider = $dbProvider;
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

		return $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresc_model' ] )
			->from( 'ores_classification' )
			->where( [
				'oresc_rev' => $revisions,
				'oresc_model' => $modelIds,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
	}

}
