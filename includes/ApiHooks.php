<?php

namespace ORES;

use ApiBase;
use ApiQueryAllRevisions;
use ApiQueryBase;
use ApiQueryContributions;
use ApiQueryGeneratorBase;
use ApiQueryRecentChanges;
use ApiQueryRevisions;
use ApiQueryWatchlist;
use ApiResult;
use DeferredUpdates;
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use Title;
use ResultWrapper;
use WatchedItem;
use WatchedItemQueryService;

/**
 * @author Brad Jorsch <bjorsch@wikimedia.org>
 */
class ApiHooks {

	/**
	 * Inject parameters into certain API modules
	 *
	 * - Adds an 'oresscores' prop to ApiQueryRevisions, ApiQueryAllRevisions,
	 *   ApiQueryRecentChanges, ApiQueryWatchlist, and ApiQueryContributions
	 * - Adds 'oresreview' and '!oresreview' to the 'show' parameters of
	 *   ApiQueryRecentChanges, ApiQueryWatchlist, and ApiQueryContributions.
	 *
	 * The actual implementations of these new parameters are handled by the
	 * various hook functions below and by \ORES\WatchedItemQueryServiceExtension.
	 *
	 * @param ApiBase &$module Module
	 * @param array &$params Parameter data
	 * @param int $flags zero or OR-ed flags like ApiBase::GET_VALUES_FOR_HELP
	 */
	public static function onAPIGetAllowedParams( &$module, &$params, $flags ) {
		if ( $module instanceof ApiQueryRevisions ||
			$module instanceof ApiQueryAllRevisions ||
			$module instanceof ApiQueryRecentChanges ||
			$module instanceof ApiQueryWatchlist ||
			$module instanceof ApiQueryContributions
		) {
			$params['prop'][ApiBase::PARAM_TYPE][] = 'oresscores';
		}

		if ( Hooks::isModelEnabled( 'damaging' ) && (
			$module instanceof ApiQueryRecentChanges ||
			$module instanceof ApiQueryWatchlist ||
			$module instanceof ApiQueryContributions
		) ) {
			$params['show'][ApiBase::PARAM_TYPE][] = 'oresreview';
			$params['show'][ApiBase::PARAM_TYPE][] = '!oresreview';
			$params['show'][ApiBase::PARAM_HELP_MSG_APPEND][] = 'ores-api-show-note';
		}
	}

	/**
	 * Modify the API query before it's made.
	 *
	 * This mainly adds the joins and conditions necessary to implement the
	 * 'oresreview' and '!oresreview' values added to the 'show' parameters of
	 * ApiQueryRecentChanges and ApiQueryContributions.
	 *
	 * It also ensures that the query from ApiQueryRecentChanges includes the
	 * fields necessary to process rcprop=oresscores.
	 *
	 * @warning Any joins added *must* join on a unique key of the target table
	 *  unless you really know what you're doing.
	 * @param ApiQueryBase $module
	 * @param array &$tables tables to be queried
	 * @param array &$fields columns to select
	 * @param array &$conds WHERE conditionals for query
	 * @param array &$options options for the database request
	 * @param array &$joinConds join conditions for the tables
	 * @param array &$hookData Inter-hook communication
	 */
	public static function onApiQueryBaseBeforeQuery(
		ApiQueryBase $module, &$tables, &$fields, &$conds, &$options, &$joinConds, &$hookData
	) {
		$params = $module->extractRequestParams();

		if ( $module instanceof ApiQueryRecentChanges ) {
			$field = 'rc_this_oldid';

			// Make sure the needed fields are included in the query, if necessary
			if ( !$module->isInGeneratorMode() && in_array( 'oresscores', $params['prop'], true ) ) {
				if ( !in_array( 'rc_this_oldid', $fields, true ) ) {
					$fields[] = 'rc_this_oldid';
				}
				if ( !in_array( 'rc_type', $fields, true ) ) {
					$fields[] = 'rc_type';
				}
			}
		} elseif ( $module instanceof ApiQueryContributions ) {
			$field = 'rev_id';
		} else {
			return;
		}

		$show = Hooks::isModelEnabled( 'damaging' ) && isset( $params['show'] )
			? array_flip( $params['show'] )
			: [];
		if ( isset( $show['oresreview'] ) || isset( $show['!oresreview'] ) ) {
			if ( isset( $show['oresreview'] ) && isset( $show['!oresreview'] ) ) {
				if ( is_callable( [ $module, 'dieWithError' ] ) ) {
					$module->dieWithError( 'apierror-show' );
				} else {
					$module->dieUsageMsg( 'show' );
				}
			}

			$threshold = Hooks::getThreshold( 'damaging', $module->getUser() );
			$dbr = \wfGetDB( DB_REPLICA );

			$tables[] = 'ores_model';
			$tables[] = 'ores_classification';

			if ( isset( $show['oresreview'] ) ) {
				$join = 'INNER JOIN';

				// Filter out non-damaging and unscored edits.
				$conds[] = 'oresc_probability > ' . $dbr->addQuotes( $threshold );
			} else {
				$join = 'LEFT JOIN';

				// Filter out damaging edits.
				$conds[] = $dbr->makeList( [
					'oresc_probability <= ' . $dbr->addQuotes( $threshold ),
					'oresc_probability IS NULL'
				], $dbr::LIST_OR );
			}

			$joinConds['ores_model'] = [ $join,
				'oresm_name = ' . $dbr->addQuotes( 'damaging' ) . ' AND oresm_is_current = 1'
			];
			$joinConds['ores_classification'] = [ $join,
				"$field = oresc_rev AND oresc_model = oresm_id AND oresc_class = 1"
			];
		}
	}

	/**
	 * Perform work after the API query is made
	 *
	 * This fetches the data necessary to handle the 'oresscores' prop added to
	 * ApiQueryRevisions, ApiQueryAllRevisions, ApiQueryRecentChanges, and
	 * ApiQueryContributions, to avoid having to make up to 5000 fetches to do
	 * it individually per row.
	 *
	 * The list of revids is extracted from $res and scores are fetched using
	 * self::loadScoresForRevisions(). The following keys are written into
	 * $hookData, if our ApiQueryBaseProcessRow hook function needs to do
	 * anything at all:
	 *  - oresField: (string) Field in the result rows holding the revid
	 *  - oresCheckRCType: (bool) Whether to skip rows where rc_type is not
	 *    RC_EDIT or RC_NEW.
	 *  - oresScores: (array) Array of arrays of row objects holding the scores
	 *    for each revision we were able to fetch.
	 *  - oresNeedsContinuation: (bool) Whether there are any rows in the
	 *    module's dataset that aren't in 'oresScores'. If this is true,
	 *    self::onApiQueryBaseProcessRow() will signal for continuation,
	 *    otherwise it will ignore any missing rows on the assumption that
	 *    something weird is going on.
	 *
	 * @param ApiQueryBase $module
	 * @param ResultWrapper|bool $res
	 * @param array &$hookData Inter-hook communication
	 */
	public static function onApiQueryBaseAfterQuery( ApiQueryBase $module, $res, &$hookData ) {
		if ( !$res ) {
			return;
		}

		// If the module is being used as a generator, don't bother. Generators
		// don't return props.
		if ( $module instanceof ApiQueryGeneratorBase && $module->isInGeneratorMode() ) {
			return;
		}

		if ( $module instanceof ApiQueryRevisions ||
			$module instanceof ApiQueryAllRevisions ||
			$module instanceof ApiQueryContributions
		) {
			$field = 'rev_id';
			$checkRCType = false;
		} elseif ( $module instanceof ApiQueryRecentChanges ) {
			$field = 'rc_this_oldid';
			$checkRCType = true;
		} else {
			return;
		}

		$params = $module->extractRequestParams();
		if ( in_array( 'oresscores', $params['prop'], true ) ) {
			// Extract revision IDs from the result set
			$revids = [];
			foreach ( $res as $row ) {
				if ( !$checkRCType || (int)$row->rc_type === RC_EDIT || (int)$row->rc_type === RC_NEW ) {
					$revids[] = $row->$field;
				}
			}
			$res->rewind();

			if ( $revids ) {
				$hookData['oresField'] = $field;
				$hookData['oresCheckRCType'] = $checkRCType;
				list( $scores, $needsContinuation ) = self::loadScoresForRevisions( $revids );
				$hookData['oresScores'] = $scores;
				$hookData['oresNeedsContinuation'] = $needsContinuation;
			}
		}
	}

	/**
	 * Load ORES score data for a list of revisions
	 *
	 * Scores already cached are fetched from the database, and up to
	 * $wgOresRevisionsPerBatch uncached revisions are fetched from the scoring
	 * service immediately. If there are still more uncached revisions, up to
	 * $wgOresAPIMaxBatchJobs FetchScoreJobs are submitted to the job queue for
	 * batches of $wgOresRevisionsPerBatch revisions in the hope that they will
	 * get a chance to run before the client continues the query.
	 *
	 * @param int[] $revids Revision IDs
	 * @return array [ array $scores, bool $needsContinuation ]
	 */
	public static function loadScoresForRevisions( $revids ) {
		global $wgOresAPIMaxBatchJobs, $wgOresRevisionsPerBatch;

		$needsContinuation = false;
		$scores = [];

		// Load cached score data
		$dbr = \wfGetDB( DB_REPLICA );
		$res2 = $dbr->select(
			[ 'ores_classification', 'ores_model' ],
			[ 'oresc_rev', 'oresc_class', 'oresc_probability', 'oresm_name' ],
			[
				'oresc_rev' => $revids,
				'oresc_model = oresm_id',
				'oresm_is_current' => 1,
			],
			__METHOD__
		);
		foreach ( $res2 as $row ) {
			$scores[$row->oresc_rev][] = $row;
		}

		// If any queried revisions were not cached, fetch up to
		// $wgOresRevisionsPerBatch from the service now, cache them, and
		// add them to the result.
		$revids = array_diff( $revids, array_keys( $scores ) );
		if ( $revids ) {
			if ( count( $revids ) > $wgOresRevisionsPerBatch ) {
				$needsContinuation = true;
				$chunks = array_chunk( $revids, $wgOresRevisionsPerBatch );
				$revids = array_shift( $chunks );
				$title = Title::makeTitle( NS_SPECIAL, 'Badtitle/API batch score fetch' );
				foreach ( array_slice( $chunks, 0, $wgOresAPIMaxBatchJobs ) as $batch ) {
					$job = new FetchScoreJob( $title, [ 'revid' => $batch, 'extra_params' => [] ] );
					JobQueueGroup::singleton()->push( $job );
				}
			}
			$loadedScores = Scoring::instance()->getScores( $revids );
			$cache = Cache::instance();
			$cache->setErrorCallback( function ( $mssg, $revision ) {
				$logger = LoggerFactory::getInstance( 'ORES' );
				$logger->info( "Scoring errored for $revision: $mssg\n" );
			} );
			DeferredUpdates::addCallableUpdate( function() use ( $cache, $loadedScores ) {
				$cache->storeScores( $loadedScores );
			} );

			$models = [];
			$res2 = $dbr->select(
				[ 'ores_model' ],
				[ 'oresm_id', 'oresm_name' ],
				[ 'oresm_is_current' => 1 ],
				__METHOD__
			);
			foreach ( $res2 as $row ) {
				$models[$row->oresm_id] = $row->oresm_name;
			}

			foreach ( $loadedScores as $revid => $data ) {
				$dbData = [];
				$cache->processRevision( $dbData, $revid, $data );
				foreach ( $dbData as $row ) {
					$scores[$revid][] = (object)[
						'oresc_class' => $row['oresc_class'],
						'oresc_probability' => $row['oresc_probability'],
						'oresm_name' => $models[$row['oresc_model']],
					];
				}
			}

			if ( !$needsContinuation && array_diff( $revids, array_keys( $loadedScores ) ) ) {
				// Some queried revisions were ignored, signal continuation.
				$needsContinuation = true;
			}
		}

		return [ $scores, $needsContinuation ];
	}

	/**
	 * Modify each data row before it's returned.
	 *
	 * This uses the data added to $hookData by
	 * self::onApiQueryBaseAfterQuery() to actually inject the scores into the
	 * result data structure. See the documentation of that method for the
	 * details of that data.
	 *
	 * @param ApiQueryBase $module
	 * @param object $row
	 * @param array &$data
	 * @param array &$hookData Inter-hook communication
	 * @return bool False to stop processing the result set
	 */
	public static function onApiQueryBaseProcessRow( $module, $row, &$data, &$hookData ) {
		if ( isset( $hookData['oresField'] ) &&
			( !$hookData['oresCheckRCType'] ||
				(int)$row->rc_type === RC_NEW || (int)$row->rc_type === RC_EDIT
			)
		) {
			$data['oresscores'] = [];

			$revid = $row->{$hookData['oresField']};
			if ( !isset( $hookData['oresScores'][$revid] ) ) {
				// If we didn't fetch all uncached scores, signal continuation.
				// Otherwise, we have a WTF situation that we should just ignore.
				return !$hookData['oresNeedsContinuation'];
			}

			self::addScoresForAPI( $data, $hookData['oresScores'][$revid] );
		}

		return true;
	}

	/**
	 * Helper to actuall add scores to an API result array
	 *
	 * @param array &$data Output array
	 * @param array $scores Array of score data
	 */
	private static function addScoresForAPI( array &$data, array $scores ) {
		global $wgOresModelClasses;
		static $classMap = null;

		if ( $classMap === null ) {
			$classMap = array_map( 'array_flip', $wgOresModelClasses );
		}

		foreach ( $scores as $row ) {
			if ( !isset( $classMap[$row->oresm_name][$row->oresc_class] ) ) {
				// Missing configuration, ignore it
				continue;
			}
			$data['oresscores'][$row->oresm_name][$classMap[$row->oresm_name][$row->oresc_class]] =
				(float)$row->oresc_probability;
		}

		foreach ( $data['oresscores'] as $model => &$outputScores ) {
			// Recalculate the class-0 result, as it's not stored in the database
			if ( isset( $classMap[$model][0] ) && !isset( $outputScores[$classMap[$model][0]] ) ) {
				$outputScores[$classMap[$model][0]] = 1.0 - array_sum( $outputScores );
			}

			ApiResult::setArrayType( $outputScores, 'kvp', 'name' );
			ApiResult::setIndexedTagName( $outputScores, 'class' );
		}
		unset( $outputScores );

		ApiResult::setArrayType( $data['oresscores'], 'kvp', 'name' );
		ApiResult::setIndexedTagName( $data['oresscores'], 'model' );
	}

	/**
	 * Inject \ORES\WatchedItemQueryServiceExtension
	 *
	 * @param \WatchedItemQueryServiceExtension[] &$extensions
	 * @param WatchedItemQueryService $queryService
	 */
	public static function onWatchedItemQueryServiceExtensions(
		&$extensions, WatchedItemQueryService $queryService
	) {
		$extensions[] = new WatchedItemQueryServiceExtension();
	}

	/**
	 * Convert API parameters to WatchedItemQueryService options
	 *
	 * @param ApiQueryBase $module
	 * @param array $params
	 * @param array &$options
	 */
	public static function onApiQueryWatchlistPrepareWatchedItemQueryServiceOptions(
		ApiQueryBase $module, $params, &$options
	) {
		if ( in_array( 'oresscores', $params['prop'], true ) ) {
			$options['includeFields'][] = 'oresscores';
		}

		$show = isset( $params['show'] ) ? array_flip( $params['show'] ) : [];
		if ( isset( $show['oresreview'] ) || isset( $show['!oresreview'] ) ) {
			if ( isset( $show['oresreview'] ) && isset( $show['!oresreview'] ) ) {
				if ( is_callable( [ $module, 'dieWithError' ] ) ) {
					$module->dieWithError( 'apierror-show' );
				} else {
					$module->dieUsageMsg( 'show' );
				}
			}

			$options['filters'][] = isset( $show['oresreview'] ) ? 'oresreview' : '!oresreview';
		}
	}

	/**
	 * Add data to ApiQueryWatchlist output
	 *
	 * @param ApiQueryBase $module
	 * @param WatchedItem $watchedItem
	 * @param array $recentChangeInfo
	 * @param array &$output
	 */
	public static function onApiQueryWatchlistExtractOutputData(
		ApiQueryBase $module, WatchedItem $watchedItem, $recentChangeInfo, &$output
	) {
		if ( isset( $recentChangeInfo['oresScores'] ) ) {
			self::addScoresForAPI( $output, $recentChangeInfo['oresScores'] );
		}
	}

}
