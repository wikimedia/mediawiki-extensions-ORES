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
use ORES\Parser\ScoreParser;
use RuntimeException;
use Wikimedia\Rdbms\LoadBalancer;

class SqlScoreStorage implements ScoreStorage {

	private $loadBalancer;

	private $modelLookup;

	public function __construct(
		LoadBalancer $loadBalancer,
		ModelLookup $modelLookup
	) {
		$this->loadBalancer = $loadBalancer;
		$this->modelLookup = $modelLookup;
	}

	/**
	 * @see ModelLookup::getModelId()
	 *
	 * @param array[] $scores
	 * @param callable $errorCallback
	 * @return int
	 */
	public function storeScores( $scores, callable $errorCallback = null ) {
		// TODO: Make it an argument and deprecate the whole config variable
		global $wgOresModelClasses;

		if ( $errorCallback === null ) {
			$errorCallback = function ( $mssg, $revision ) {
				throw new RuntimeException( "Model contains an error for $revision: $mssg" );
			};
		}

		$dbData = [];

		$scoreParser = new ScoreParser( $this->modelLookup, $wgOresModelClasses );
		foreach ( $scores as $revision => $revisionData ) {
			try {
				$dbDataPerRevision = $scoreParser->processRevision( $revision, $revisionData );
			} catch ( InvalidArgumentException $exception ) {
				call_user_func( $errorCallback, $exception->getMessage(), $revision );
				continue;
			}

			$dbData = array_merge( $dbData, $dbDataPerRevision );
		}

		$this->loadBalancer->getConnection( DB_MASTER )->insert(
			'ores_classification',
			$dbData,
			__METHOD__,
			[ 'IGNORE' ]
		);
	}

}
