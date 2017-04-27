<?php

namespace ORES;

use IDatabase;
use ResultWrapper;
use User;

/**
 * @author Brad Jorsch <bjorsch@wikimedia.org>
 */
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

		$show = Hooks::isModelEnabled( 'damaging' ) && isset( $options['filters'] )
			? array_flip( $options['filters'] )
			: [];
		if ( isset( $show['oresreview'] ) || isset( $show['!oresreview'] ) ) {
			$threshold = Hooks::getThreshold( 'damaging', $user );

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

			$joinConds['ores_model'] = [ $join,
				'oresm_name = ' . $db->addQuotes( 'damaging' ) . ' AND oresm_is_current = 1'
			];
			$joinConds['ores_classification'] = [ $join,
				"rc_this_oldid = oresc_rev AND oresc_model = oresm_id AND oresc_class = 1"
			];
		}
	}

	/**
	 * Modify the result
	 *
	 * This handles the 'oresscores' value in 'includeFields': it collects the
	 * applicable revision IDs, loads scores for them (using
	 * ApiHooks::loadScoresForRevisions()), and adds the scoring data to the
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
			list( $scores, $needsContinuation ) = ApiHooks::loadScoresForRevisions( $revids );
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
