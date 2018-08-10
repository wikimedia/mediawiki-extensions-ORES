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

use RuntimeException;

/**
 * Service interface for retrieving score data from API.
 *
 * @license GPL-3.0-or-later
 */
interface ServiceScoreLookup {

	/**
	 * Method to retrieve scores of given revision and models
	 *
	 * @param int|int[] $revisions Single or multiple revision IDs
	 * @param string|string[]|null $models Single or multiple model names. If
	 * left empty, all configured models are queried.
	 * @param bool $precache either the request is made for precaching or not
	 *
	 * @return array Results in the form returned by ORES API
	 * @throws RuntimeException
	 */
	public function getScores( $revisions, $models = null, $precache = false );

}
