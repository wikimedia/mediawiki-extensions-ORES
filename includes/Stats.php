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

	private $thresholdsConfig = [
		'damaging' => [
			'likelygood' => [
				'min' => 0,
				'max' => [
					'stat' => 'recall_at_precision(min_precision=0.98)',
					'outcome' => 'false',
					'default' => 0.55,
				]
			],
			'maybebad' => [
				'min' => [
					'stat' => 'recall_at_precision(min_precision=0.15)',
					'outcome' => 'true',
					'default' => 0.16,
				],
				'max' => 1,
			],
			'likelybad' => [
				'min' => [
					'stat' => 'recall_at_precision(min_precision=0.45)',
					'outcome' => 'true',
					'default' => 0.75,
				],
				'max' => 1,
			],
			'verylikelybad' => [
				'min' => [
					'stat' => 'recall_at_precision(min_precision=0.9)',
					'outcome' => 'true',
					'default' => 0.92,
				],
				'max' => 1,
			],
		],
		'goodfaith' => [
			'good' => [
				'min' => [
					'stat' => 'recall_at_precision(min_precision=0.98)',
					'outcome' => 'true',
					'default' => 0.35,
				],
				'max' => 1,
			],
			'maybebad' => [
				'min' => 0,
				'max' => [
					'stat' => 'recall_at_precision(min_precision=0.15)',
					'outcome' => 'false',
					'default' => 0.65,
				],
			],
			'bad' => [
				'min' => 0,
				'max' => [
					'stat' => 'recall_at_precision(min_precision=0.45)',
					'outcome' => 'false',
					'default' => 0.15,
				],
			],
		],
	];

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
		if ( isset( $this->thresholdsConfig[ $model ] ) ) {
			return $this->parseThresholds( $this->fetchStats( $model, $fromCache ), $model );
		} else {
			return [];
		}
	}

	private function fetchStats( $model, $fromCache ) {
		if ( $fromCache ) {
			$key = $this->cache->makeKey( 'ORES', 'test_stats', $model );
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
		foreach ( $this->thresholdsConfig[ $model ] as $levelName => $config ) {
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

			$thresholds[ $levelName ] = [
				'min' => $min,
				'max' => $max,
			];
		}
		return $thresholds;
	}

	private function extractBoundValue( $model, $levelName, $bound, $config, $statsData ) {
		if ( is_numeric( $config ) ) {
			return $config;
		}

		$stat = $config[ 'stat' ];
		$outcome = $config[ 'outcome' ];
		if ( isset( $statsData[ $stat ][ $outcome ][ 'threshold' ] ) ) {
			return $statsData[ $stat ][ $outcome ][ 'threshold' ];
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

		return $config[ 'default' ];
	}

	public static function newFromGlobalState() {
		return new self(
			new Api(),
			MediaWikiServices::getInstance()->getMainWANObjectCache(),
			LoggerFactory::getInstance( 'ORES' )
		);
	}

}
