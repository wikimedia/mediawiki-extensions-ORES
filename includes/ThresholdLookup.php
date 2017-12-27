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
use Psr\Log\LoggerInterface;
use WANObjectCache;

class ThresholdLookup {

	/**
	 * @var Api
	 */
	private $api;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var ThresholdParser
	 */
	private $thresholdParser;

	/**
	 * @param Api $api
	 * @param WANObjectCache $cache
	 * @param LoggerInterface $logger
	 */
	public function __construct( Api $api, WANObjectCache $cache, LoggerInterface $logger ) {
		$this->api = $api;
		$this->cache = $cache;
		$this->logger = $logger;
		// TODO: Inject
		$this->thresholdParser = new ThresholdParser( $logger );
	}

	public function getThresholds( $model, $fromCache = true ) {
		$config = $this->thresholdParser->getFiltersConfig( $model );
		// Skip if the model is unconfigured or set to false.
		if ( $config ) {
			$stats = $this->fetchStats( $model, $fromCache );
			// Skip if stats are empty.
			if ( $stats !== false ) {
				return $this->thresholdParser->parseThresholds( $stats, $model );
			}
		}
		return [];
	}

	private function fetchStats( $model, $fromCache ) {
		global $wgOresCacheVersion;
		if ( $fromCache ) {
			$key = $this->cache->makeKey( 'ORES', 'threshold_statistics', $model, $wgOresCacheVersion );
			$result = $this->cache->getWithSetCallback(
				$key,
				WANObjectCache::TTL_DAY,
				function ( $oldValue, &$ttl, &$setOpts, $opts ) use ( $model ) {
					$statsdDataFactory = MediaWikiServices::getInstance()->getStatsdDataFactory();
					// @deprecated Only catching exceptions to allow the
					// failure to be cached, remove once transition is
					// complete.
					try {
						$result = $this->fetchStatsFromApi( $model );
						$statsdDataFactory->increment( 'ores.api.stats.ok' );

						return $result;
					} catch ( \RuntimeException $ex ) {
						// TODO: We can also check the service *before* the
						// cached value expires, and therefore reuse the old
						// value until the service recovers in case of failure.
						$statsdDataFactory->increment( 'ores.api.stats.failed' );
						$this->logger->error( 'Failed to fetch ORES stats.' );

						// Retry again soon.
						$ttl = WANObjectCache::TTL_MINUTE;
						return [];
					}
				},
				[
					// Try to only let one datacenter thread manage cache updates at a time
					'lockTSE' => 10,
					// Avoid querying cache servers multiple times in a web request
					'pcTTL' => WANObjectCache::TTL_PROC_LONG,
				]
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
		foreach ( $this->thresholdParser->getFiltersConfig( $model ) as $levelName => $config ) {
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

}
