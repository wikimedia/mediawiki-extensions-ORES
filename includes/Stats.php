<?php

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
				// FIXME: Should be TTL_DAY, set to TTL_MINUTE during the breaking transition.
				\WANObjectCache::TTL_MINUTE,
				function () use ( $model ) {
					// @deprecated Only catching exceptions to allow the
					// failure to be cached, remove once transition is
					// complete.
					try {
						return $this->fetchStatsFromApi( $model );
					} catch ( \RuntimeException $ex ) {
						// Magic to trigger an exception.
						return -1;
					}
				}
			);
			// @deprecated Magic exception to allow caching of failure and
			// falling back to StatsV1.
			if ( $result === -1 ) {
				throw new \RuntimeException( 'Cached failure.' );
			}
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
		$wikiId = $this->api->getWikiID();

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
			return $formula;
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
		if ( $bound === 'max' && isset( $statsData['false'][$stat]['threshold'] ) ) {
			$threshold = $statsData['false'][$stat]['threshold'];
			// Invert to turn a "false" threshold to "true".
			$threshold = 1 - $threshold;
			return $threshold;
		} elseif ( isset( $statsData['true'][$stat]['threshold'] ) ) {
			$threshold = $statsData['true'][$stat]['threshold'];
			return $threshold;
		}

		throw new \RuntimeException(
			'Unable to parse threshold: ' . json_encode(
			[
				'levelName' => $levelName,
				'levelConfig' => $config,
				'bound' => $bound,
				'statsData' => $statsData,
			] )
		);
	}

	/**
	 * @return self|StatsV1
	 */
	public static function newFromGlobalState() {
		$logger = LoggerFactory::getInstance( 'ORES' );
		$stats = new self(
			Api::newFromContext(),
			MediaWikiServices::getInstance()->getMainWANObjectCache(),
			$logger
		);

		// Test to see if the new-style thresholds are supported by the server.
		// There is a short race condition here, if the server is downgraded
		// between this check and the outer stack frame calling
		// $stats->getThresholds, but the failure should safely result in an
		// empty result.
		// Note that we're relying on the cache TTL, cached revscoring 2.x
		// results are returned until they expire, at which point the next call
		// fails, and we start returning StatsV1 objects.  When the new-style
		// backend starts working again, we call that once cached empty results
		// expire.
		// @deprecated Remove fallback code once migration is completed.
		try {
			$stats->fetchStats( 'damaging', true );
			// If this didn't throw an exception, go ahead and use the new stats object.
			return $stats;
		} catch ( \RuntimeException $exception ) {
			$logger->info( "Falling back to old threshold stats: [{$exception->getMessage()}]" );
			return StatsV1::newFromGlobalState();
		}
	}

}
