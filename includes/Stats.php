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

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

class Stats {

	/**
	 * @var Api
	 */
	private $api;

	/**
	 * @var \WANObjectCache
	 */
	private $cache;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @param Api $api
	 * @param \WANObjectCache $cache
	 * @param LoggerInterface $logger
	 */
	public function __construct( Api $api, \WANObjectCache $cache, LoggerInterface $logger ) {
		$this->api = $api;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	public function getThresholds( $model, $fromCache = true ) {
		$config = $this->getFiltersConfig( $model );
		// Skip if the model is unconfigured or set to false.
		if ( $config ) {
			$stats = $this->tryFetchStats( $model, $fromCache );
			// Skip if stats are empty.
			if ( $stats !== false ) {
				return $this->parseThresholds( $stats, $model );
			}
		}
		return [];
	}

	private function getFiltersConfig( $model ) {
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
					$formula = $this->mungeV1Forumula( $formula );
				}
			}
		}
		return $config;
	}

	private function tryFetchStats( $model, $fromCache ) {
		try {
			return $this->fetchStats( $model, $fromCache );
		} catch ( \RuntimeException $exception ) {
			$this->logger->error( 'Failed to fetch ORES stats: ' . $exception->getMessage() );
			return false;
		}
	}

	private function fetchStats( $model, $fromCache ) {
		global $wgOresCacheVersion;
		if ( $fromCache ) {
			$key = $this->cache->makeKey( 'ORES', 'threshold_statistics', $model, $wgOresCacheVersion );
			$result = $this->cache->getWithSetCallback(
				$key,
				\WANObjectCache::TTL_DAY,
				function () use ( $model ) {
					$statsdDataFactory = MediaWikiServices::getInstance()->getStatsdDataFactory();
					// @deprecated Only catching exceptions to allow the
					// failure to be cached, remove once transition is
					// complete.
					try {
						$result = $this->fetchStatsFromApi( $model );
						$statsdDataFactory->increment( 'ores.api.stats.ok' );
						return $result;
					} catch ( \RuntimeException $ex ) {
						$statsdDataFactory->increment( 'ores.api.stats.failed' );
						throw $ex;
					}
				}
			);
			return $result;
		} else {
			$this->logger->info( 'Forcing stats fetch, bypassing cache.' );
			return $this->fetchStatsFromApi( $model );
		}
	}

	private function fetchStatsFromApi( $model ) {
		$trueFormulas = [];
		$falseFormulas = [];
		$calculatedThresholds = [];
		foreach ( $this->getFiltersConfig( $model ) as $levelName => $config ) {
			if ( $config === false ) {
				continue;
			}
			foreach ( $config as $bound => $formula ) {
				$outcome = ( $bound === 'min' ) ? 'true' : 'false';
				// Collect calculated field formulas.
				// FIXME: Don't rely on magic patterns to identify calculated fields.

				if ( false !== strpos( $formula, '@' ) ) {
					// Formula, add it to the list of stats to fetch.

					// Write as an API parameter.
					$calculatedThresholds[] = "statistics.thresholds.{$outcome}.\"{$formula}\"";
					if ( $outcome === 'true' ) {
						$trueFormulas[] = $formula;
					} else {
						$falseFormulas[] = $formula;
					}
				}
			}
		}

		if ( count( $calculatedThresholds ) === 0 ) {
			// If nothing needs to be calculated, don't hit the API.
			return [];
		}

		$formulaParam = implode( "|", $calculatedThresholds );
		$data = $this->api->request( [ 'models' => $model, 'model_info' => $formulaParam ] );
		$wikiId = Api::getWikiID();

		// Traverse the data path.
		$prefix = [ $wikiId, 'models', $model, 'statistics', 'thresholds' ];

		$resultMap = [];

		if ( $falseFormulas ) {
			$pathParts = array_merge( $prefix, [ 'false' ] );
			$result = $this->extractKeyPath( $data, $pathParts );

			// Interpret the response in the same order as we built our request.
			// FIXME: This is fragile.  Better if the results have identifying keys.
			foreach ( $falseFormulas as $index => $formula ) {
				$resultMap['false'][$formula] = $result[$index];
			}
		}

		if ( $trueFormulas ) {
			$pathParts = array_merge( $prefix, [ 'true' ] );
			$result = $this->extractKeyPath( $data, $pathParts );

			// Interpret the response in the same order as we built our request.
			// This seems fragile enough to warrant a FIXME.
			foreach ( $trueFormulas as $index => $formula ) {
				$resultMap['true'][$formula] = $result[$index];
			}
		}

		return $resultMap;
	}

	/**
	 * Converts an old-style configuration to new-style.
	 * @deprecated Can be removed once all threshold config is written in the new grammar.
	 */
	protected function mungeV1Forumula( $v1Formula ) {
		if ( false !== strpos( $v1Formula, '@' ) ) {
			// This is new-style already, pass through.
			return $v1Formula;
		} elseif ( preg_match(
			'/recall_at_precision\(min_precision=(0\.\d+)\)/',
			$v1Formula, $matches )
		) {
			$min_precision = floatval( $matches[1] );
			return "maximum recall @ precision >= {$min_precision}";
		} elseif ( preg_match(
			'/filter_rate_at_recall\(min_recall=(0\.\d+)\)/',
			$v1Formula, $matches )
		) {
			$min_recall = floatval( $matches[1] );
			return "maximum filter_rate @ recall >= {$min_recall}";
		} else {
			// We ran out of guesses.
			throw new \RuntimeException( "Failed to parse threshold formula [{$v1Formula}]" );
		}
	}

	protected function extractKeyPath( $data, $keyPath ) {
		$current = $data;
		foreach ( $keyPath as $key ) {
			if ( !isset( $current[$key] ) ) {
				$fullPath = implode( '.', $keyPath );
				throw new \RuntimeException( "Failed to parse data at key [{$fullPath}]" );
			}
			$current = $current[$key];
		}
		return $current;
	}

	private function parseThresholds( $statsData, $model ) {
		$thresholds = [];
		foreach ( $this->getFiltersConfig( $model ) as $levelName => $config ) {
			if ( $config === false ) {
				// level is disabled
				continue;
			}

			$min = $this->extractBoundValue(
				$levelName,
				'min',
				$config['min'],
				$statsData
			);

			$max = $this->extractBoundValue(
				$levelName,
				'max',
				$config['max'],
				$statsData
			);

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

	private function extractBoundValue( $levelName, $bound, $config, $statsData ) {
		if ( is_numeric( $config ) ) {
			return $config;
		}

		$stat = $config;
		if ( $bound === 'max' && $statsData['false'][$stat] === null ) {
			return null;
		} elseif ( $bound === 'max' && isset( $statsData['false'][$stat]['threshold'] ) ) {
			$threshold = $statsData['false'][$stat]['threshold'];
			// Invert to turn a "false" threshold to "true".
			$threshold = 1 - $threshold;
			return $threshold;
		} elseif ( isset( $statsData['true'][$stat]['threshold'] ) ) {
			$threshold = $statsData['true'][$stat]['threshold'];
			return $threshold;
		}

		return null;
	}

	/**
	 * @return self
	 */
	public static function newFromGlobalState() {
		return new self(
			Api::newFromContext(),
			MediaWikiServices::getInstance()->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'ORES' )
		);
	}

}
