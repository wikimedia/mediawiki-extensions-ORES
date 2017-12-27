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

use MediaWiki\MediaWikiServices;
use RuntimeException;

class Cache {

	static protected $modelIds;

	protected $errorCallback;

	public function __construct() {
		$this->setErrorCallback( function ( $mssg, $revision ) {
			throw new RuntimeException( "Model contains an error for $revision: $mssg" );
		} );
	}

	/**
	 * Setter for $errorCallback
	 *
	 * @param callable $errorCallback the callback function
	 */
	public function setErrorCallback( callable $errorCallback ) {
		$this->errorCallback = $errorCallback;
	}

	/**
	 * Reduce score data so that it only includes revisions from a whitelist.
	 * @param array[] $scores in the same structure as is returned by ORES.
	 * @param int[] $acceptedRevids List of revision ids to accept.
	 * @return array[] Filtered scores.
	 */
	public function filterScores( array $scores, array $acceptedRevids ) {
		return array_intersect_key( $scores, array_flip( $acceptedRevids ) );
	}

	/**
	 * Save scores to the database
	 * FIXME: Move responsibility for response processing to the Scoring class.
	 *
	 * @param array[] $scores in the same structure as is returned by ORES.
	 *
	 * @throws RuntimeException
	 */
	public function storeScores( array $scores ) {
		$dbData = [];

		foreach ( $scores as $revision => $revisionData ) {
			$this->processRevision( $dbData, $revision, $revisionData );
		}

		\wfGetDB( DB_MASTER )->insert( 'ores_classification', $dbData, __METHOD__, [ 'IGNORE' ] );
	}

	/**
	 * Purge a given set of rows. Intended for use by the RecentChangesPurgeRows hook only.
	 *
	 * @param int[] $revIds Array of revision IDs
	 */
	public function purgeRows( array $revIds ) {
		$dbw = \wfGetDB( DB_MASTER );
		// Delete everything in one go. If it works for recentchanges, it works for us.
		$dbw->delete( 'ores_classification',
			[ 'oresc_rev' => $revIds ],
			__METHOD__
		);
	}

	/**
	 * Convert data returned by Scoring::getScores() into ores_classification rows
	 *
	 * @note No row is generated for class 0
	 * @param array &$dbData Rows for insertion into ores_classification are added to this array
	 * @param int $revision Revision being processed
	 * @param array $revisionData Data returned by Scoring::getScores() for the revision.
	 *
	 * @throws RuntimeException
	 */
	public function processRevision( array &$dbData, $revision, array $revisionData ) {
		global $wgOresModelClasses;
		// Map to database fields.

		$modelLookup = MediaWikiServices::getInstance()->getService( 'ORESModelLookup' );
		foreach ( $revisionData as $model => $modelOutputs ) {
			if ( isset( $modelOutputs['error'] ) ) {
				call_user_func( $this->errorCallback, $modelOutputs['error']['message'], $revision );
				continue;
			}

			$prediction = $modelOutputs['score']['prediction'];
			// Kludge out booleans so we can match prediction against class name.
			if ( $prediction === false ) {
				$prediction = 'false';
			} elseif ( $prediction === true ) {
				$prediction = 'true';
			}

			$modelId = $modelLookup->getModelId( $model );
			if ( !isset( $wgOresModelClasses[ $model ] ) ) {
				throw new RuntimeException( "Model $model is not configured" );
			}
			foreach ( $modelOutputs['score']['probability'] as $class => $probability ) {
				$ores_is_predicted = $prediction === $class;
				if ( !isset( $wgOresModelClasses[ $model ][ $class ] ) ) {
					throw new RuntimeException( "Class $class in model $model is not configured" );
				}
				$class = $wgOresModelClasses[ $model ][ $class ];
				if ( $class === 0 ) {
					// We don't store rows for class 0, because we can compute the class 0 probability by
					// subtracting the sum of the probabilities of the other classes from 1
					continue;
				}
				$dbData[] = [
					'oresc_rev' => $revision,
					'oresc_model' => $modelId,
					'oresc_class' => $class,
					'oresc_probability' => $probability,
					'oresc_is_predicted' => ( $ores_is_predicted ),
				];
			}
		}
	}

	public static function instance() {
		return new self();
	}

}
