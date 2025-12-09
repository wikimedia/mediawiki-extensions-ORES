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

use Psr\Log\LoggerInterface;

class ThresholdParser {

	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}

	public function parseThresholds( array $statsData, string $model ): array {
		$thresholds = [];
		foreach ( $this->getFiltersConfig( $model ) as $levelName => $config ) {
			if ( $config === false ) {
				// level is disabled
				continue;
			}

			$min = $this->extractBoundValue( 'min', $config['min'], $statsData );

			$max = $this->extractBoundValue( 'max', $config['max'], $statsData );

			if ( $max === null || $min === null ) {
				$data = [
					'levelName' => $levelName,
					'levelConfig' => $config,
					'max' => $max,
					'min' => $min,
					'statsData' => $statsData,
				];
				$this->logger->error( 'Unable to parse threshold: ' . json_encode( $data ) );
				continue;
			}

			if ( is_numeric( $min ) && is_numeric( $max ) ) {
				$thresholds[$levelName] = [
					'min' => $min,
					'max' => $max,
				];
			}
		}

		return $thresholds;
	}

	/**
	 * @param string $model
	 *
	 * @return (array|bool)[]|false
	 */
	public function getFiltersConfig( $model ) {
		global $wgOresFiltersThresholds;
		if ( !isset( $wgOresFiltersThresholds[$model] ) ) {
			return false;
		}
		$config = $wgOresFiltersThresholds[$model];
		return $config;
	}

	/**
	 * @param string $bound
	 * @param string|int $config
	 * @param array $statsData
	 * @return int|null
	 */
	private function extractBoundValue( $bound, $config, array $statsData ) {
		if ( is_numeric( $config ) ) {
			return $config;
		}

		$stat = $config;
		if ( !isset( $statsData['false'] ) || !isset( $statsData['true'] ) ) {
			return null;
		}

		if ( !isset( $statsData['false'][$stat] ) && !isset( $statsData['true'][$stat] ) ) {
			return null;
		}

		if ( $bound === 'max' && $statsData['false'][$stat] === null ) {
			return null;
		} elseif ( $bound === 'max' && isset( $statsData['false'][$stat]['threshold'] ) ) {
			$threshold = $statsData['false'][$stat]['threshold'];
			$threshold = 1 - $threshold;

			return $threshold;
		} elseif ( isset( $statsData['true'][$stat]['threshold'] ) ) {
			$threshold = $statsData['true'][$stat]['threshold'];

			return $threshold;
		}

		return null;
	}

}
