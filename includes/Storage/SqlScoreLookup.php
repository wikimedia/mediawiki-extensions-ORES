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

use ORES\ScoreLookup;
use Wikimedia\Rdbms\LoadBalancer;
use Wikimedia\Rdbms\ResultWrapper;

class SqlScoreLookup implements ScoreLookup {

	private $modelLookup;

	private $loadBalancer;

	public function __construct(
		ModelLookup $modelLookup,
		LoadBalancer $loadBalancer
	) {
		$this->modelLookup = $modelLookup;
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * Method to retrieve scores of given revision and models
	 *
	 * @param int|array $revisions Single or multiple revisions
	 * @param string|array|null $models Single or multiple model names.  If
	 * left empty, all configured models are queried.
	 * @param bool $precache either the request is made for precaching or not
	 *
	 * @todo This method return scores in a syntax that is different than the other implementations
	 * Either they should implement different interfaces or make the other one return a parsed
	 * output
	 *
	 * @return ResultWrapper
	 */
	public function getScores( $revisions, $models = null, $precache = false ) {
		if ( !$models ) {
			global $wgOresModels;
			$models = array_keys( array_filter( $wgOresModels ) );
		}

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
