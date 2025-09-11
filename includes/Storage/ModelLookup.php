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
 * Service interface for retrieving model data from storage.
 *
 * @license GPL-3.0-or-later
 */
interface ModelLookup {

	/**
	 * @param string $model
	 * @return int ID of last seen version
	 * @throws ModelNotFoundError
	 */
	public function getModelId( $model );

	/**
	 * @param string $model
	 * @return string version number of the model
	 * @throws ModelNotFoundError
	 */
	public function getModelVersion( $model );

	/**
	 * Returns models and their latest data from storage
	 *
	 * @return array[] Array of [ 'id' => int $id, 'version' => string $version ],
	 *    indexed by model name.
	 */
	public function getModels();

}
