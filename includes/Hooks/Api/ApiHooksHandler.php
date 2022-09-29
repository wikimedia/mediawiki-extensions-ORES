<?php
/**
 * Copyright (C) 2016 Brad Jorsch <bjorsch@wikimedia.org>
 *
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

namespace ORES\Hooks\Api;

use ApiBase;
use ApiQueryAllRevisions;
use ApiQueryBase;
use ApiQueryGeneratorBase;
use ApiQueryRecentChanges;
use ApiQueryRevisions;
use ApiQueryUserContribs;
use ApiQueryWatchlist;
use ApiResult;
use ORES\Hooks\Helpers;
use ORES\Services\ORESServices;
use WatchedItem;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IResultWrapper;

class ApiHooksHandler {

	/**
	 * Inject parameters into certain API modules
	 *
	 * - Adds an 'oresscores' prop to ApiQueryRevisions, ApiQueryAllRevisions,
	 *   ApiQueryRecentChanges, ApiQueryWatchlist, and ApiQueryUserContribs
	 * - Adds 'oresreview' and '!oresreview' to the 'show' parameters of
	 *   ApiQueryRecentChanges, ApiQueryWatchlist, and ApiQueryUserContribs.
	 *
	 * The actual implementations of these new parameters are handled by the
	 * various hook functions below and by \ORES\WatchedItemQueryServiceExtension.
	 *
	 * @param ApiBase $module
	 * @param array &$params
	 * @param int $flags zero or OR-ed flags like ApiBase::GET_VALUES_FOR_HELP
	 */
	public static function onAPIGetAllowedParams( ApiBase $module, array &$params, $flags ) {
		if ( $module instanceof ApiQueryRevisions ||
			$module instanceof ApiQueryAllRevisions ||
			$module instanceof ApiQueryRecentChanges ||
			$module instanceof ApiQueryWatchlist ||
			$module instanceof ApiQueryUserContribs
		) {
			$params['prop'][ParamValidator::PARAM_TYPE][] = 'oresscores';
		}

		if ( Helpers::isModelEnabled( 'damaging' ) && (
			$module instanceof ApiQueryRecentChanges ||
			$module instanceof ApiQueryWatchlist ||
			$module instanceof ApiQueryUserContribs
		) ) {
			$params['show'][ParamValidator::PARAM_TYPE][] = 'oresreview';
			$params['show'][ParamValidator::PARAM_TYPE][] = '!oresreview';
			$params['show'][ApiBase::PARAM_HELP_MSG_APPEND][] = 'ores-api-show-note';
		}
	}

	/**
	 * Modify the API query before it's made.
	 *
	 * This mainly adds the joins and conditions necessary to implement the
	 * 'oresreview' and '!oresreview' values added to the 'show' parameters of
	 * ApiQueryRecentChanges and ApiQueryUserContribs.
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
		} elseif ( $module instanceof ApiQueryUserContribs ) {
			$field = 'rev_id';
		} else {
			return;
		}

		$show = Helpers::isModelEnabled( 'damaging' )
			? array_flip( $params['show'] ?? [] )
			: [];
		if ( isset( $show['oresreview'] ) || isset( $show['!oresreview'] ) ) {
			if ( isset( $show['oresreview'] ) && isset( $show['!oresreview'] ) ) {
				$module->dieWithError( 'apierror-show' );
			}

			$threshold =
				Helpers::getThreshold( 'damaging', $module->getUser(), $module->getTitle() );
			$dbr = wfGetDB( DB_REPLICA );

			$tables[] = 'ores_classification';

			if ( isset( $show['oresreview'] ) ) {
				$join = 'INNER JOIN';

				// Filter out non-damaging and unscored edits.
				$conds[] = 'oresc_probability > ' . $dbr->addQuotes( $threshold );

				// Performance hack: add STRAIGHT_JOIN (T146111)
				$options[] = 'STRAIGHT_JOIN';
			} else {
				$join = 'LEFT JOIN';

				// Filter out damaging edits.
				$conds[] = $dbr->makeList( [
					'oresc_probability <= ' . $dbr->addQuotes( $threshold ),
					'oresc_probability IS NULL'
				], $dbr::LIST_OR );
			}

			$modelId = ORESServices::getModelLookup()->getModelId( 'damaging' );
			$joinConds['ores_classification'] = [ $join, [
				"oresc_rev=$field",
				'oresc_model' => $modelId,
				'oresc_class' => 1
			] ];
		}
	}

	/**
	 * Perform work after the API query is made
	 *
	 * This fetches the data necessary to handle the 'oresscores' prop added to
	 * ApiQueryRevisions, ApiQueryAllRevisions, ApiQueryRecentChanges, and
	 * ApiQueryUserContribs, to avoid having to make up to 5000 fetches to do
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
	 *
	 * @param ApiQueryBase $module
	 * @param IResultWrapper|bool $res
	 * @param array &$hookData Inter-hook communication
	 */
	public static function onApiQueryBaseAfterQuery( ApiQueryBase $module, $res, array &$hookData ) {
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
			$module instanceof ApiQueryUserContribs
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
				$scores = self::loadScoresForRevisions( $revids );
				$hookData['oresScores'] = $scores;
			}
		}
	}

	/**
	 * Load ORES score data for a list of revisions
	 *
	 * Scores already cached are fetched from the database.
	 *
	 * @param int[] $revids Revision IDs
	 * @return array
	 */
	public static function loadScoresForRevisions( array $revids ) {
		$scores = [];
		$models = [];
		foreach ( ORESServices::getModelLookup()->getModels() as $modelName => $modelDatum ) {
			$models[$modelDatum['id']] = $modelName;
		}

		// Load cached score data
		$dbResult = ORESServices::getScoreLookup()->getScores( $revids, array_values( $models ) );
		foreach ( $dbResult as $row ) {
			$scores[$row->oresc_rev][] = $row;
		}

		return $scores;
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
	 * @param \stdClass $row
	 * @param array &$data
	 * @param array &$hookData Inter-hook communication
	 * @return bool False to stop processing the result set
	 */
	public static function onApiQueryBaseProcessRow(
		ApiQueryBase $module,
		$row,
		array &$data,
		array &$hookData
	) {
		if ( isset( $hookData['oresField'] ) &&
			( !$hookData['oresCheckRCType'] ||
				(int)$row->rc_type === RC_NEW || (int)$row->rc_type === RC_EDIT
			)
		) {
			$data['oresscores'] = [];
			$revid = $row->{$hookData['oresField']};

			$modelData = ORESServices::getModelLookup()->getModels();
			$models = [];
			foreach ( $modelData as $modelName => $modelDatum ) {
				$models[$modelDatum['id']] = $modelName;
			}

			if ( !isset( $hookData['oresScores'][$revid] ) ) {
				return true;
			}

			self::addScoresForAPI( $data, $hookData['oresScores'][$revid], $models );
		}

		return true;
	}

	/**
	 * Helper to actuall add scores to an API result array
	 *
	 * @param array &$data Output array
	 * @param \stdClass[] $scores Array of score data
	 * @param array $models
	 */
	private static function addScoresForAPI( array &$data, array $scores, array $models ) {
		global $wgOresModelClasses;
		static $classMap = null;

		if ( $classMap === null ) {
			$classMap = array_map( 'array_flip', $wgOresModelClasses );
		}

		foreach ( $scores as $row ) {
			if ( !isset( $row->oresm_name ) && isset( $row->oresc_model ) ) {
				$row->oresm_name = $models[$row->oresc_model];
			}

			if ( !isset( $row->oresm_name ) || !isset( $classMap[$row->oresm_name][$row->oresc_class] ) ) {
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
	 * Convert API parameters to WatchedItemQueryService options
	 *
	 * @param ApiQueryBase $module
	 * @param array $params
	 * @param array &$options
	 */
	public static function onApiQueryWatchlistPrepareWatchedItemQueryServiceOptions(
		ApiQueryBase $module, array $params, array &$options
	) {
		if ( in_array( 'oresscores', $params['prop'], true ) ) {
			$options['includeFields'][] = 'oresscores';
		}

		$show = array_flip( $params['show'] ?? [] );
		if ( isset( $show['oresreview'] ) || isset( $show['!oresreview'] ) ) {
			if ( isset( $show['oresreview'] ) && isset( $show['!oresreview'] ) ) {
				$module->dieWithError( 'apierror-show' );
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
		ApiQueryBase $module, WatchedItem $watchedItem, array $recentChangeInfo, array &$output
	) {
		if ( isset( $recentChangeInfo['oresScores'] ) ) {
			$modelData = ORESServices::getModelLookup()->getModels();

			$models = [];
			foreach ( $modelData as $modelName => $modelDatum ) {
				$models[$modelDatum['id']] = $modelName;
			}
			self::addScoresForAPI( $output, $recentChangeInfo['oresScores'], $models );
		}
	}

}
