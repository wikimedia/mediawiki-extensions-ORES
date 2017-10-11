<?php

namespace ORES;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

class StatsV1 {

	/**
	 * @var ApiV1
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
	 * @param ApiV1 $api
	 * @param \WANObjectCache $cache
	 * @param LoggerInterface $logger
	 */
	public function __construct( ApiV1 $api, \WANObjectCache $cache, LoggerInterface $logger ) {
		$this->api = $api;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	public function getThresholds( $model, $fromCache = true ) {
		if ( $this->getFiltersConfig( $model ) ) {
			$stats = $this->tryFetchStats( $model, $fromCache );
			if ( $stats !== false ) {
				return $this->parseThresholds( $stats, $model );
			}
		}
		return [];
	}

	private function getFiltersConfig( $model ) {
		global $wgOresFiltersThresholds;
		return isset( $wgOresFiltersThresholds[ $model ] ) ? $wgOresFiltersThresholds[ $model ] : false;
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
			$key = $this->cache->makeKey( 'ORES', 'test_stats', $model, $wgOresCacheVersion );
			return $this->cache->getWithSetCallback(
				$key,
				\WANObjectCache::TTL_DAY,
				function () use ( $model ) {
					return $this->fetchStatsFromApi( $model );
				}
			);
		} else {
			return $this->fetchStatsFromApi( $model );
		}
	}

	private function fetchStatsFromApi( $model ) {
		$data = $this->api->request( [ 'model_info' => 'test_stats' ], $model );
		if ( isset( $data[ 'test_stats' ] ) ) {
			return $data[ 'test_stats' ];
		}
	}

	private function parseThresholds( $statsData, $model ) {
		$thresholds = [];
		foreach ( $this->getFiltersConfig( $model ) as $levelName => $config ) {
			if ( $config === false ) {
				// level is disabled
				continue;
			}

			$min = $this->extractBoundValue(
				$model,
				$levelName,
				'min',
				$config[ 'min' ],
				$statsData
			);

			$max = $this->extractBoundValue(
				$model,
				$levelName,
				'max',
				$config[ 'max' ],
				$statsData
			);

			if ( is_numeric( $min ) && is_numeric( $max ) ) {
				$thresholds[$levelName] = [
					'min' => $min,
					'max' => $max,
				];
			}
		}
		return $thresholds;
	}

	private function extractBoundValue( $model, $levelName, $bound, $config, $statsData ) {
		if ( is_numeric( $config ) ) {
			return $config;
		}

		$stat = $config;
		$outcome = $bound === 'min' ? 'true' : 'false';
		if ( isset( $statsData[$stat][$outcome]['threshold'] ) ) {
			$threshold = $statsData[$stat][$outcome]['threshold'];
			// Thresholds reported for "false" outcomes apply to "false" scores, but we always
			// apply thresholds against "true" scores, so we need to invert "false" thresholds here.
			return $outcome === 'false' ? 1 - $threshold : $threshold;
		}

		$this->logger->warning(
			'Unable to parse threshold.',
			[
				'model' => $model,
				'levelName' => $levelName,
				'levelConfig' => $config,
				'bound' => $bound,
				'statsData' => print_r( $statsData, true ),
			]
		);
	}

	/**
	 * @return self
	 */
	public static function newFromGlobalState() {
		return new self(
			ApiV1::newFromContext(),
			MediaWikiServices::getInstance()->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'ORES' )
		);
	}

}
