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

/**
 * Service interface to store score data in storage.
 *
 * @license GPL-3.0-or-later
 */
interface ScoreStorage {

	/**
	 * Save scores to the database
	 *
	 * @param array[] $scores in the same structure as is returned by ORES.
	 * @param callable|null $errorCallback This callback is called when we cannot parse a revision
	 *   score response. The signature is errorCallback( string $errorMessage, string $revisionID )
	 * @param string[] $modelsToClean Models that need cleanup of old scores after inserting new ones
	 */
	public function storeScores( $scores, callable $errorCallback = null, array $modelsToClean = [] );

	/**
	 * Purge a given set of revision ids.
	 *
	 * @param int[] $revIds array of revision ids to remove from cached scores
	 */
	public function purgeRows( array $revIds );

}
