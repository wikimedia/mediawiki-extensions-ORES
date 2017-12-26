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
use ORES\Storage\ModelLookup;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
	 * @var ModelLookup
	 */
	private $modelLookup;

	/**
	 * @var ThresholdParser
	 */
	private $thresholdParser;

	/**
	 * @param Api $api
	 * @param WANObjectCache $cache
	 * @param LoggerInterface $logger
	 * @param ModelLookup $modelLookup
	 */
	public function __construct(
		Api $api,
		WANObjectCache $cache,
		LoggerInterface $logger,
		ModelLookup $modelLookup
	) {
		$this->api = $api;
		$this->cache = $cache;
		$this->logger = $logger;
		$this->modelLookup = $modelLookup;
		// TODO: Inject
		$this->thresholdParser = new ThresholdParser( $logger );
	}

	public function getThresholds( $model, $fromCache = true ) {
		$config = $this->thresholdParser->getFiltersConfig( $model );
		if ( $config ) {
			$stats = $this->fetchThresholds( $model, $fromCache );
			if ( $stats !== false ) {
				return $this->thresholdParser->parseThresholds( $stats, $model );
			}
		}
		return [];
	}

	private function fetchThresholds( $model, $fromCache ) {
		if ( $fromCache ) {
			return $this->fetchThresholdsFromCache( $model );
		} else {
			$this->logger->info( 'Forcing stats fetch, bypassing cache.' );
			return $this->fetchThresholdsFromApi( $model );
		}
	}

	private function fetchThresholdsFromCache( $model ) {
		global $wgOresCacheVersion;
		$modelVersion = $this->modelLookup->getModelVersion( $model );
		$key = $this->cache->makeKey(
			'ORES',
			'threshold_statistics',
			$model,
			$modelVersion,
			$wgOresCacheVersion
		);
		return $this->cache->getWithSetCallback(
			$key,
			WANObjectCache::TTL_DAY,
			function ( $oldValue, &$ttl, &$setOpts, $opts ) use ( $model ) {
				$statsdDataFactory = MediaWikiServices::getInstance()->getStatsdDataFactory();
				try {
					$result = $this->fetchThresholdsFromApi( $model );
					$statsdDataFactory->increment( 'ores.api.stats.ok' );

					return $result;
				} catch ( RuntimeException $ex ) {
					$statsdDataFactory->increment( 'ores.api.stats.failed' );
					$this->logger->error( 'Failed to fetch ORES stats.' );

					$ttl = WANObjectCache::TTL_MINUTE;
					return [];
				}
			},
			[ 'lockTSE' => 10, 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);
	}

	private function fetchThresholdsFromApi( $model ) {
		$trueFormulas = [];
		$falseFormulas = [];
		$calculatedThresholds = [];
		foreach ( $this->thresholdParser->getFiltersConfig( $model ) as $levelName => $config ) {
			if ( $config === false ) {
				continue;
			}
			foreach ( $config as $bound => $formula ) {
				$outcome = ( $bound === 'min' ) ? 'true' : 'false';
				if ( false !== strpos( $formula, '@' ) ) {
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
			return [];
		}

		$formulaParam = implode( "|", $calculatedThresholds );
		$data = $this->api->request( [ 'models' => $model, 'model_info' => $formulaParam ] );
		$wikiId = Api::getWikiID();

		$prefix = [ $wikiId, 'models', $model, 'statistics', 'thresholds' ];

		$resultMap = [];

		if ( $falseFormulas ) {
			$pathParts = array_merge( $prefix, [ 'false' ] );
			$result = $this->extractKeyPath( $data, $pathParts );

			foreach ( $falseFormulas as $index => $formula ) {
				$resultMap['false'][$formula] = $result[$index];
			}
		}

		if ( $trueFormulas ) {
			$pathParts = array_merge( $prefix, [ 'true' ] );
			$result = $this->extractKeyPath( $data, $pathParts );

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
				throw new RuntimeException( "Failed to parse data at key [{$fullPath}]" );
			}
			$current = $current[$key];
		}

		return $current;
	}

}
