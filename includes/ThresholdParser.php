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
use RuntimeException;

class ThresholdParser {

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	public function parseThresholds( array $statsData, $model ) {
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
	 * @return array|bool
	 */
	public function getFiltersConfig( $model ) {
		global $wgOresFiltersThresholds;
		if ( !isset( $wgOresFiltersThresholds[$model] ) ) {
			return false;
		}
		$config = $wgOresFiltersThresholds[$model];

		// Convert old config to new grammar.
		// @deprecated Remove once all config is migrated.
		foreach ( $config as $levelName => &$levelConfig ) {
			if ( $levelConfig === false ) {
				continue;
			}
			foreach ( $levelConfig as $bound => &$formula ) {
				if ( false !== strpos( $formula, '(' ) ) {
					// Old-style formula, convert it to new-style.
					$formula = $this->mungeV1Formula( $formula );
				}
			}
		}
		return $config;
	}

	/**
	 * Converts an old-style configuration to new-style.
	 * @deprecated Can be removed once all threshold config is written in the new grammar.
	 */
	private function mungeV1Formula( $v1Formula ) {
		if ( false !== strpos( $v1Formula, '@' ) ) {
			return $v1Formula;
		} elseif ( preg_match( '/recall_at_precision\(min_precision=(0\.\d+)\)/', $v1Formula,
			$matches ) ) {
			$min_precision = floatval( $matches[1] );

			return "maximum recall @ precision >= {$min_precision}";
		} elseif ( preg_match( '/filter_rate_at_recall\(min_recall=(0\.\d+)\)/', $v1Formula,
			$matches ) ) {
			$min_recall = floatval( $matches[1] );

			return "maximum filter_rate @ recall >= {$min_recall}";
		} else {
			throw new RuntimeException( "Failed to parse threshold formula [{$v1Formula}]" );
		}
	}

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
