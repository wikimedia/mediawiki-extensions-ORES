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

namespace ORES;

use MediaWiki\MediaWikiServices;
use ORES\Hooks\ApiHooksHandler;
use ORES\Hooks\Helpers;
use User;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ResultWrapper;

class WatchedItemQueryServiceExtension implements \WatchedItemQueryServiceExtension {

	/**
	 * Modify the query
	 *
	 * This adds the joins and conditions necessary to implement the
	 * 'oresreview' and '!oresreview' filters, and ensures that query includes
	 * the fields necessary to handle the 'oresscores' value in 'includeFields'.
	 *
	 * @warning Any joins added *must* join on a unique key of the target table
	 *  unless you really know what you're doing.
	 * @param User $user
	 * @param array $options Options from
	 *  WatchedItemQueryService::getWatchedItemsWithRecentChangeInfo()
	 * @param IDatabase $db Database connection being used for the query
	 * @param array &$tables Tables for Database::select()
	 * @param array &$fields Fields for Database::select()
	 * @param array &$conds Conditions for Database::select()
	 * @param array &$dbOptions Options for Database::select()
	 * @param array &$joinConds Join conditions for Database::select()
	 */
	public function modifyWatchedItemsWithRCInfoQuery( User $user, array $options, IDatabase $db,
		array &$tables, array &$fields, array &$conds, array &$dbOptions, array &$joinConds
	) {
		if ( !$options['usedInGenerator'] && in_array( 'oresscores', $options['includeFields'], true ) ) {
			if ( !in_array( 'rc_this_oldid', $fields, true ) ) {
				$fields[] = 'rc_this_oldid';
			}
			if ( !in_array( 'rc_type', $fields, true ) ) {
				$fields[] = 'rc_type';
			}
		}

		$show = Helpers::isModelEnabled( 'damaging' ) && isset( $options['filters'] )
			? array_flip( $options['filters'] )
			: [];
		if ( isset( $show['oresreview'] ) || isset( $show['!oresreview'] ) ) {
			$threshold = Helpers::getThreshold( 'damaging', $user );

			$tables[] = 'ores_model';
			$tables[] = 'ores_classification';

			if ( isset( $show['oresreview'] ) ) {
				$join = 'INNER JOIN';

				// Filter out non-damaging and unscored edits.
				$conds[] = 'oresc_probability > ' . $db->addQuotes( $threshold );
			} else {
				$join = 'LEFT JOIN';

				// Filter out damaging edits.
				$conds[] = $db->makeList( [
					'oresc_probability <= ' . $db->addQuotes( $threshold ),
					'oresc_probability IS NULL'
				], $db::LIST_OR );
			}

			$modelId = MediaWikiServices::getInstance()->getService( 'ORESModelLookup' )
				->getModelId( 'damaging' );
			$joinConds['ores_classification'] = [ $join, [
				'rc_this_oldid=oresc_rev',
				'oresc_model' => $modelId,
				'oresc_class' => 1
			] ];
		}
	}

	/**
	 * Modify the result
	 *
	 * This handles the 'oresscores' value in 'includeFields': it collects the
	 * applicable revision IDs, loads scores for them (using
	 * ApiHooksHandler::loadScoresForRevisions()), and adds the scoring data to the
	 * $recentChangeInfo portion of $items. If all scores were not available
	 * and the API is able to fetch them later, it truncates $items and adjusts
	 * $startFrom accordingly.
	 *
	 * @param User $user
	 * @param array $options Options from
	 *  WatchedItemQueryService::getWatchedItemsWithRecentChangeInfo()
	 * @param IDatabase $db Database connection being used for the query
	 * @param array &$items array of pairs ( WatchedItem $watchedItem, string[] $recentChangeInfo )
	 * @param ResultWrapper|bool $res Database query result
	 * @param array|null &$startFrom Continuation value
	 */
	public function modifyWatchedItemsWithRCInfo( User $user, array $options, IDatabase $db,
		array &$items, $res, &$startFrom
	) {
		if ( $options['usedInGenerator'] || !in_array( 'oresscores', $options['includeFields'], true ) ) {
			return;
		}

		$revids = [];
		foreach ( $items as list( $watchedItem, $rcInfo ) ) {
			if ( (int)$rcInfo['rc_type'] === RC_EDIT || (int)$rcInfo['rc_type'] === RC_NEW ) {
				$revids[] = $rcInfo['rc_this_oldid'];
			}
		}

		if ( $revids ) {
			list( $scores, $needsContinuation ) = ApiHooksHandler::loadScoresForRevisions( $revids );
			foreach ( $items as $i => $dummy ) {
				$rcInfo = &$items[$i][1];
				if ( (int)$rcInfo['rc_type'] !== RC_EDIT && (int)$rcInfo['rc_type'] !== RC_NEW ) {
					continue;
				}

				$revid = $rcInfo['rc_this_oldid'];
				if ( isset( $scores[$revid] ) ) {
					$rcInfo['oresScores'] = $scores[$revid];
				} elseif ( $needsContinuation ) {
					$startFrom = [ $rcInfo['rc_timestamp'], $rcInfo['rc_id'] ];
					$items = array_slice( $items, 0, $i );
					break;
				}
			}
		}
	}

}