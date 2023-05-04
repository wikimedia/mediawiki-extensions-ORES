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

use ORES\ORESService;

class ThresholdLookupConfig extends ThresholdLookup {

	/**
	 * @param string $model Model name
	 * @return array with array with threshold values
	 */
	protected function fetchThresholdsFromApi( $model ) {
		$formulae = [ 'true' => [], 'false' => [] ];

		$calculatedThresholds = [];
		foreach ( $this->thresholdParser->getFiltersConfig( $model ) as $levelName => $config ) {
			if ( $config === false ) {
				continue;
			}
			$this->prepareThresholdRequestParam( $config, $formulae, $calculatedThresholds );
		}

		if ( count( $calculatedThresholds ) === 0 ) {
			return [];
		}
		$thresholds = $this->mainConfig->get( 'OresModelThresholds' );
		$wikiId = ORESService::getWikiID();
		$data = $thresholds[$wikiId]['models'][$model]['statistics']['thresholds'];

		$prefix = [ $wikiId, 'models', $model, 'statistics', 'thresholds' ];
		$resultMap = [];

		foreach ( $formulae as $outcome => $outcomeFormulae ) {
			if ( !$outcomeFormulae ) {
				continue;
			}

			$pathParts = array_merge( $prefix, [ $outcome ] );
			$result = $this->extractKeyPath( $data, $pathParts );

			foreach ( $outcomeFormulae as $index => $formula ) {
				$resultMap[$outcome][$formula] = $result[$index];
			}
		}

		return $resultMap;
	}
}
