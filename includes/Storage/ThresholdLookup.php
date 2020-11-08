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

use IBufferingStatsdDataFactory;
use ORES\ORESService;
use ORES\ThresholdParser;
use Psr\Log\LoggerInterface;
use RuntimeException;
use WANObjectCache;

class ThresholdLookup {

	/**
	 * @var ThresholdParser
	 */
	private $thresholdParser;

	/**
	 * @var ModelLookup
	 */
	private $modelLookup;

	/**
	 * @var ORESService
	 */
	private $oresService;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var IBufferingStatsdDataFactory
	 */
	private $statsdDataFactory;

	/**
	 * @param ThresholdParser $thresholdParser
	 * @param ModelLookup $modelLookup
	 * @param ORESService $oresService
	 * @param WANObjectCache $cache
	 * @param LoggerInterface $logger
	 * @param IBufferingStatsdDataFactory $statsdDataFactory
	 */
	public function __construct(
		ThresholdParser $thresholdParser,
		ModelLookup $modelLookup,
		ORESService $oresService,
		WANObjectCache $cache,
		LoggerInterface $logger,
		IBufferingStatsdDataFactory $statsdDataFactory
	) {
		$this->thresholdParser = $thresholdParser;
		$this->modelLookup = $modelLookup;
		$this->oresService = $oresService;
		$this->cache = $cache;
		$this->logger = $logger;
		$this->statsdDataFactory = $statsdDataFactory;
	}

	public function getRawThresholdData( $model, $fromCache = true ) {
		$config = $this->thresholdParser->getFiltersConfig( $model );
		if ( $config ) {
			return $this->fetchThresholds( $model, $fromCache );
		}
		return false;
	}

	public function getThresholds( $model, $fromCache = true ) {
		$stats = $this->getRawThresholdData( $model, $fromCache );
		if ( $stats !== false ) {
			return $this->thresholdParser->parseThresholds( $stats, $model );
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
			'ores_threshold_statistics',
			$model,
			$modelVersion,
			$wgOresCacheVersion,
			md5( json_encode( $this->thresholdParser->getFiltersConfig( $model ) ) )
		);
		return $this->cache->getWithSetCallback(
			$key,
			WANObjectCache::TTL_DAY,
			function ( $oldValue, &$ttl, &$setOpts, $opts ) use ( $model ) {
				try {
					$result = $this->fetchThresholdsFromApi( $model );
					$this->statsdDataFactory->increment( 'ores.api.stats.ok' );

					return $result;
				} catch ( RuntimeException $ex ) {
					$this->statsdDataFactory->increment( 'ores.api.stats.failed' );
					$this->logger->error( 'Failed to fetch ORES stats.' );

					$ttl = WANObjectCache::TTL_MINUTE;
					return [];
				}
			},
			[ 'lockTSE' => 10, 'pcTTL' => WANObjectCache::TTL_PROC_LONG ]
		);
	}

	private function fetchThresholdsFromApi( $model ) {
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

		$data = $this->oresService->request(
			[ 'models' => $model, 'model_info' => implode( "|", $calculatedThresholds ) ]
		);

		$prefix = [ ORESService::getWikiID(), 'models', $model, 'statistics', 'thresholds' ];
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

	/**
	 * @param string[] $config associative array mapping boundaries to old formulas
	 * @param array[] &$formulae associative array mapping boundaries to new formulas
	 * @param string[] &$calculatedThresholds array that has threshold request param
	 */
	private function prepareThresholdRequestParam(
		array $config,
		array &$formulae,
		array &$calculatedThresholds
	) {
		foreach ( $config as $bound => $formula ) {
			$outcome = ( $bound === 'min' ) ? 'true' : 'false';
			if ( strpos( $formula, '@' ) !== false ) {
				$calculatedThresholds[] = "statistics.thresholds.{$outcome}.\"{$formula}\"";
				$formulae[$outcome][] = $formula;
			}
		}
	}

	/**
	 * @param array $data
	 * @param string[] $keyPath
	 *
	 * @return array
	 */
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
