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

namespace ORES\Hooks;

use ChangesList;
use ChangesListBooleanFilterGroup;
use ChangesListSpecialPage;
use ChangesListStringOptionsFilterGroup;
use EnhancedChangesList;
use FormOptions;
use IContextSource;
use MediaWiki\MediaWikiServices;
use ORES\Hooks;
use ORES\Range;
use RCCacheEntry;
use RecentChange;
use SpecialRecentChanges;
use SpecialWatchlist;
use Wikimedia\Rdbms\IDatabase;

class ChangesListHooksHandler {

	public static function onChangesListSpecialPageStructuredFilters(
		ChangesListSpecialPage $clsp
	) {
		// ORES is disabled on Recentchangeslinked: T163063
		if ( !Hooks::oresUiEnabled( $clsp->getUser() ) || $clsp->getName() === 'Recentchangeslinked'
		) {
			return;
		}

		$stats = MediaWikiServices::getInstance()->getService( 'ORESThresholdLookup' );

		$changeTypeGroup = $clsp->getFilterGroup( 'changeType' );
		$logFilter = $changeTypeGroup->getFilter( 'hidelog' );

		if ( Hooks::isModelEnabled( 'damaging' ) ) {
			if ( $clsp instanceof SpecialRecentChanges ) {
				$damagingDefault = $clsp->getUser()->getOption( 'oresRCHideNonDamaging' );
				$highlightDefault = $clsp->getUser()->getBoolOption( 'ores-damaging-flag-rc' );
			} elseif ( $clsp instanceof SpecialWatchlist ) {
				$damagingDefault = $clsp->getUser()->getOption( 'oresWatchlistHideNonDamaging' );
				$highlightDefault = $clsp->getUser()->getBoolOption( 'oresHighlight' );
			} else {
				$damagingDefault = false;
				$highlightDefault = false;
			}

			$damagingLevels = $stats->getThresholds( 'damaging' );
			$filters = [];
			if ( isset( $damagingLevels[ 'likelygood' ] ) ) {
				$filters[ 'likelygood' ] = [
					'name' => 'likelygood',
					'label' => 'ores-rcfilters-damaging-likelygood-label',
					'description' => 'ores-rcfilters-damaging-likelygood-desc',
					'cssClassSuffix' => 'damaging-likelygood',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'damaging',
						$damagingLevels['likelygood']
					),
				];
			}
			if ( isset( $damagingLevels[ 'maybebad' ] ) ) {
				$filters[ 'maybebad' ] = [
					'name' => 'maybebad',
					'label' => 'ores-rcfilters-damaging-maybebad-label',
					'description' => 'ores-rcfilters-damaging-maybebad-desc',
					'cssClassSuffix' => 'damaging-maybebad',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'damaging',
						$damagingLevels['maybebad']
					),
				];
			}
			if ( isset( $damagingLevels[ 'likelybad' ] ) ) {
				$descMsg = isset( $filters[ 'maybebad' ] ) ?
					'ores-rcfilters-damaging-likelybad-desc-low' :
					'ores-rcfilters-damaging-likelybad-desc-high';
				$filters[ 'likelybad' ] = [
					'name' => 'likelybad',
					'label' => 'ores-rcfilters-damaging-likelybad-label',
					'description' => $descMsg,
					'cssClassSuffix' => 'damaging-likelybad',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'damaging',
						$damagingLevels['likelybad']
					),
				];
			}
			if ( isset( $damagingLevels[ 'verylikelybad' ] ) ) {
				$filters[ 'verylikelybad' ] = [
					'name' => 'verylikelybad',
					'label' => 'ores-rcfilters-damaging-verylikelybad-label',
					'description' => 'ores-rcfilters-damaging-verylikelybad-desc',
					'cssClassSuffix' => 'damaging-verylikelybad',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'damaging',
						$damagingLevels['verylikelybad']
					),
				];
			}

			if ( $filters ) {
				$newDamagingGroup = new ChangesListStringOptionsFilterGroup( [
					'name' => 'damaging',
					'title' => 'ores-rcfilters-damaging-title',
					'whatsThisHeader' => 'ores-rcfilters-damaging-whats-this-header',
					'whatsThisBody' => 'ores-rcfilters-damaging-whats-this-body',
					'whatsThisUrl' => 'https://www.mediawiki.org/wiki/' .
						'Special:MyLanguage/Help:New_filters_for_edit_review/Quality_and_Intent_Filters',
					'whatsThisLinkText' => 'ores-rcfilters-whats-this-link-text',
					'priority' => 2,
					'filters' => array_values( $filters ),
					'default' => ChangesListStringOptionsFilterGroup::NONE,
					'isFullCoverage' => false,
					'queryCallable' => function ( $specialClassName, $ctx, $dbr, &$tables,
							&$fields, &$conds, &$query_options, &$join_conds, $selectedValues ) {
						$condition = self::buildRangeFilter( 'damaging', $selectedValues );
						if ( $condition ) {
							$conds[] = $condition;

							// Filter out incompatible types; log actions and external rows are not scorable
							$conds[] = 'rc_type NOT IN (' . $dbr->makeList( [ RC_LOG, RC_EXTERNAL ] ) . ')';
							// Make the joins INNER JOINs instead of LEFT JOINs
							$join_conds['ores_damaging_mdl'][0] = 'INNER JOIN';
							$join_conds['ores_damaging_cls'][0] = 'INNER JOIN';
							// Performance hack: add STRAIGHT_JOIN (T146111) but not for Watchlist (T176456 / T164796)
							if ( $specialClassName !== 'SpecialWatchlist' ) {
								$query_options[] = 'STRAIGHT_JOIN';
							}
						}
					},
				] );

				$newDamagingGroup->conflictsWith(
					$logFilter,
					'ores-rcfilters-ores-conflicts-logactions-global',
					'ores-rcfilters-damaging-conflicts-logactions',
					'ores-rcfilters-logactions-conflicts-ores'
				);

				if ( isset( $filters[ 'maybebad' ] ) && isset( $filters[ 'likelybad' ] ) ) {
					$newDamagingGroup->getFilter( 'maybebad' )->setAsSupersetOf(
						$newDamagingGroup->getFilter( 'likelybad' )
					);
				}

				if ( isset( $filters[ 'likelybad' ] ) && isset( $filters[ 'verylikelybad' ] ) ) {
					$newDamagingGroup->getFilter( 'likelybad' )->setAsSupersetOf(
						$newDamagingGroup->getFilter( 'verylikelybad' )
					);
				}

				// Transitive closure
				if ( isset( $filters[ 'maybebad' ] ) && isset( $filters[ 'verylikelybad' ] ) ) {
					$newDamagingGroup->getFilter( 'maybebad' )->setAsSupersetOf(
						$newDamagingGroup->getFilter( 'verylikelybad' )
					);
				}

				if ( $damagingDefault ) {
					$newDamagingGroup->setDefault( Hooks::getDamagingLevelPreference(
						$clsp->getUser(),
						$clsp->getPageTitle()
					) );
				}

				if ( $highlightDefault ) {
					$levelsColors = [
						'maybebad' => 'c3',
						'likelybad' => 'c4',
						'verylikelybad' => 'c5',
					];

					$prefLevel = Hooks::getDamagingLevelPreference(
						$clsp->getUser(),
						$clsp->getPageTitle()
					);
					$allLevels = array_keys( $levelsColors );
					$applicableLevels = array_slice( $allLevels, array_search( $prefLevel, $allLevels ) );
					$applicableLevels = array_intersect( $applicableLevels, array_keys( $filters ) );

					foreach ( $applicableLevels as $level ) {
						$newDamagingGroup
							->getFilter( $level )
							->setDefaultHighlightColor( $levelsColors[ $level ] );
					}
				}

				$clsp->registerFilterGroup( $newDamagingGroup );
			}

			// I don't think we need to register a conflict here, since
			// if we're showing non-damaging, that won't conflict with
			// anything.
			$legacyDamagingGroup = new ChangesListBooleanFilterGroup( [
				'name' => 'ores',
				'filters' => [
					[
						'name' => 'hidenondamaging',
						'showHide' => 'ores-damaging-filter',
						'isReplacedInStructuredUi' => true,
						'default' => $damagingDefault,
						'queryCallable' => function ( $specialClassName, $ctx, $dbr, &$tables,
								&$fields, &$conds, &$query_options, &$join_conds ) {
							Hooks::hideNonDamagingFilter( $fields, $conds, true, $ctx->getUser(), $ctx->getTitle() );
							// Filter out incompatible types; log actions and external rows are not scorable
							$conds[] = 'rc_type NOT IN (' . $dbr->makeList( [ RC_LOG, RC_EXTERNAL ] ) . ')';
							// Filter out patrolled edits: the 'r' doesn't appear for them
							$conds['rc_patrolled'] = 0;
							// Make the joins INNER JOINs instead of LEFT JOINs
							$join_conds['ores_damaging_mdl'][0] = 'INNER JOIN';
							$join_conds['ores_damaging_cls'][0] = 'INNER JOIN';
							// Performance hack: add STRAIGHT_JOIN (T146111) but not for Watchlist (T176456 / T164796)
							if ( $specialClassName !== 'SpecialWatchlist' ) {
								$query_options[] = 'STRAIGHT_JOIN';
							}
						},
					]
				],

			] );

			$clsp->registerFilterGroup( $legacyDamagingGroup );
		}
		if ( Hooks::isModelEnabled( 'goodfaith' ) ) {
			$goodfaithLevels = $stats->getThresholds( 'goodfaith' );
			$filters = [];
			if ( isset( $goodfaithLevels['likelygood'] ) ) {
				$filters[ 'likelygood' ] = [
					'name' => 'likelygood',
					'label' => 'ores-rcfilters-goodfaith-good-label',
					'description' => 'ores-rcfilters-goodfaith-good-desc',
					'cssClassSuffix' => 'goodfaith-good',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'goodfaith',
						$goodfaithLevels['likelygood']
					),
				];
			}
			if ( isset( $goodfaithLevels['maybebad'] ) ) {
				$filters[ 'maybebad' ] = [
					'name' => 'maybebad',
					'label' => 'ores-rcfilters-goodfaith-maybebad-label',
					'description' => 'ores-rcfilters-goodfaith-maybebad-desc',
					'cssClassSuffix' => 'goodfaith-maybebad',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'goodfaith',
						$goodfaithLevels['maybebad']
					),
				];
			}
			if ( isset( $goodfaithLevels['likelybad'] ) ) {
				$descMsg = isset( $filters[ 'maybebad' ] ) ?
					'ores-rcfilters-goodfaith-bad-desc-low' :
					'ores-rcfilters-goodfaith-bad-desc-high';
				$filters[ 'likelybad' ] = [
					'name' => 'likelybad',
					'label' => 'ores-rcfilters-goodfaith-bad-label',
					'description' => $descMsg,
					'cssClassSuffix' => 'goodfaith-bad',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'goodfaith',
						$goodfaithLevels['likelybad']
					),
				];
			}
			if ( isset( $goodfaithLevels['verylikelybad'] ) ) {
				$filters[ 'verylikelybad' ] = [
					'name' => 'verylikelybad',
					'label' => 'ores-rcfilters-goodfaith-verylikelybad-label',
					'description' => 'ores-rcfilters-goodfaith-verylikelybad-desc',
					'cssClassSuffix' => 'goodfaith-verylikelybad',
					'isRowApplicableCallable' => self::makeApplicableCallback(
						'goodfaith',
						$goodfaithLevels['verylikelybad']
					),
				];
			}

			if ( $filters ) {
				$goodfaithGroup = new ChangesListStringOptionsFilterGroup( [
					'name' => 'goodfaith',
					'title' => 'ores-rcfilters-goodfaith-title',
					'whatsThisHeader' => 'ores-rcfilters-goodfaith-whats-this-header',
					'whatsThisBody' => 'ores-rcfilters-goodfaith-whats-this-body',
					'whatsThisUrl' => 'https://www.mediawiki.org/wiki/' .
						'Special:MyLanguage/Help:New_filters_for_edit_review/Quality_and_Intent_Filters',
					'whatsThisLinkText' => 'ores-rcfilters-whats-this-link-text',
					'priority' => 1,
					'filters' => array_values( $filters ),
					'default' => ChangesListStringOptionsFilterGroup::NONE,
					'isFullCoverage' => false,
					'queryCallable' => function ( $specialClassName, $ctx, $dbr, &$tables, &$fields,
							&$conds, &$query_options, &$join_conds, $selectedValues ) {
						$condition = self::buildRangeFilter( 'goodfaith', $selectedValues );
						if ( $condition ) {
							$conds[] = $condition;

							// Filter out incompatible types; log actions and external rows are not scorable
							$conds[] = 'rc_type NOT IN (' . $dbr->makeList( [ RC_LOG, RC_EXTERNAL ] ) . ')';
							// Make the joins INNER JOINs instead of LEFT JOINs
							$join_conds['ores_goodfaith_mdl'][0] = 'INNER JOIN';
							$join_conds['ores_goodfaith_cls'][0] = 'INNER JOIN';
							// Performance hack: add STRAIGHT_JOIN (T146111) but not for Watchlist (T176456 / T164796)
							if ( $specialClassName !== 'SpecialWatchlist' ) {
								$query_options[] = 'STRAIGHT_JOIN';
							}
						}
					},
				] );

				if ( isset( $filters['maybebad'] ) && isset( $filters['likelybad'] ) ) {
					$goodfaithGroup->getFilter( 'maybebad' )->setAsSupersetOf(
						$goodfaithGroup->getFilter( 'likelybad' )
					);
				}

				if ( isset( $filters['likelybad'] ) && isset( $filters['verylikelybad'] ) ) {
					$goodfaithGroup->getFilter( 'likelybad' )->setAsSupersetOf(
						$goodfaithGroup->getFilter( 'verylikelybad' )
					);
				}

				if ( isset( $filters['maybebad'] ) && isset( $filters['verylikelybad'] ) ) {
					$goodfaithGroup->getFilter( 'maybebad' )->setAsSupersetOf(
						$goodfaithGroup->getFilter( 'verylikelybad' )
					);
				}

				$goodfaithGroup->conflictsWith(
					$logFilter,
					'ores-rcfilters-ores-conflicts-logactions-global',
					'ores-rcfilters-goodfaith-conflicts-logactions',
					'ores-rcfilters-logactions-conflicts-ores'
				);

				$clsp->registerFilterGroup( $goodfaithGroup );
			}
		}
	}

	public static function onChangesListSpecialPageQuery(
		$name, array &$tables, array &$fields, array &$conds,
		array &$query_options, array &$join_conds, FormOptions $opts
	) {
		global $wgUser;

		// ORES is disabled on Recentchangeslinked: T163063
		if ( !Hooks::oresUiEnabled( $wgUser ) || $name === 'Recentchangeslinked' ) {
			return;
		}

		if ( Hooks::isModelEnabled( 'damaging' ) ) {
			Hooks::joinWithOresTables(
				'damaging',
				'rc_this_oldid',
				$tables,
				$fields,
				$join_conds
			);
		}
		if ( Hooks::isModelEnabled( 'goodfaith' ) ) {
			Hooks::joinWithOresTables(
				'goodfaith',
				'rc_this_oldid',
				$tables,
				$fields,
				$join_conds
			);
		}
	}

	/**
	 * Label recent changes with ORES scores (for each change in an expanded group)
	 *
	 * @param EnhancedChangesList $ecl
	 * @param array &$data
	 * @param RCCacheEntry[] $block
	 * @param RCCacheEntry $rcObj
	 * @param string[] &$classes
	 */
	public static function onEnhancedChangesListModifyLineData(
		EnhancedChangesList $ecl,
		array &$data,
		array $block,
		RCCacheEntry $rcObj,
		array &$classes
	) {
		if ( !Hooks::oresUiEnabled( $ecl->getUser() ) ) {
			return;
		}

		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );
	}

	/**
	 * Label recent changes with ORES scores (for top-level ungrouped lines)
	 *
	 * @param EnhancedChangesList $ecl
	 * @param array &$data
	 * @param RCCacheEntry $rcObj
	 */
	public static function onEnhancedChangesListModifyBlockLineData(
		EnhancedChangesList $ecl,
		array &$data,
		RCCacheEntry $rcObj
	) {
		if ( !Hooks::oresUiEnabled( $ecl->getUser() ) ) {
			return;
		}

		$classes = [];
		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );
		$data['attribs']['class'] = array_merge( $data['attribs']['class'], $classes );
	}

	/**
	 * Internal helper to label matching rows
	 *
	 * @param RCCacheEntry $rcObj
	 * @param string[] &$data
	 * @param string[] &$classes
	 * @param IContextSource $context
	 */
	protected static function processRecentChangesList(
		RCCacheEntry $rcObj,
		array &$data,
		array &$classes = [],
		IContextSource $context
	) {
		$damaging = self::getScoreRecentChangesList( $rcObj, $context );

		if ( $damaging && Hooks::isDamagingFlagEnabled( $context ) ) {
			$classes[] = 'damaging';
			$data['recentChangesFlags']['damaging'] = true;
		}
	}

	/**
	 * Hook for formatting recent changes links
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/OldChangesListRecentChangesLine
	 *
	 * @param ChangesList &$changesList
	 * @param string &$s
	 * @param RecentChange $rc
	 * @param string[] &$classes
	 * @return bool|void
	 */
	public static function onOldChangesListRecentChangesLine(
		ChangesList &$changesList,
		&$s,
		RecentChange $rc,
		array &$classes = []
	) {
		if ( !Hooks::oresUiEnabled( $changesList->getUser() ) ) {
			return;
		}

		$damaging = self::getScoreRecentChangesList( $rc, $changesList->getContext() );
		if ( $damaging ) {
			// Add highlight class
			if ( Hooks::isHighlightEnabled( $changesList ) ) {
				$classes[] = 'ores-highlight';
			}

			// Add damaging class and flag
			if ( Hooks::isDamagingFlagEnabled( $changesList ) ) {
				$classes[] = 'damaging';

				$separator = ' <span class="mw-changeslist-separator">. .</span> ';
				if ( strpos( $s, $separator ) === false ) {
					return;
				}

				$parts = explode( $separator, $s );
				$parts[1] = ChangesList::flag( 'damaging' ) . $parts[1];
				$s = implode( $separator, $parts );
			}
		}
	}

	/**
	 * Check if we should flag a row. As a side effect, also adds score data for this row.
	 * @param RecentChange $rcObj
	 * @param IContextSource $context
	 * @return bool
	 */
	public static function getScoreRecentChangesList( RecentChange $rcObj, IContextSource $context ) {
		$threshold = $rcObj->getAttribute( 'ores_damaging_threshold' );
		if ( $threshold === null ) {
			$threshold = Hooks::getThreshold( 'damaging', $context->getUser(), $context->getTitle() );
		}
		$score = $rcObj->getAttribute( 'ores_damaging_score' );
		$patrolled = $rcObj->getAttribute( 'rc_patrolled' );
		$type = $rcObj->getAttribute( 'rc_type' );

		// Log actions and external rows are not scorable; if such a row does have a score, ignore it
		if ( !$score || $threshold === null || in_array( $type, [ RC_LOG, RC_EXTERNAL ] ) ) {
			// Shorten out
			return false;
		}

		Hooks::addRowData(
			$context,
			$rcObj->getAttribute( 'rc_this_oldid' ),
			(float)$score,
			'damaging'
		);

		return $score && $score >= $threshold && !$patrolled;
	}

	private static function makeApplicableCallback( $model, array $levelData ) {
		return function ( $ctx, $rc ) use ( $model, $levelData ) {
			$score = $rc->getAttribute( "ores_{$model}_score" );
			$type = $rc->getAttribute( 'rc_type' );
			// Log actions and external rows are not scorable; if such a row does have a score, ignore it
			if ( $score === null || in_array( $type, [ RC_LOG, RC_EXTERNAL ] ) ) {
				return false;
			}
			return $levelData['min'] <= $score && $score <= $levelData['max'];
		};
	}

	private static function buildRangeFilter( $name, $filterValue ) {
		$stats = MediaWikiServices::getInstance()->getService( 'ORESThresholdLookup' );
		$thresholds = $stats->getThresholds( $name );

		$selectedLevels = is_array( $filterValue ) ? $filterValue :
			explode( ',', strtolower( $filterValue ) );
		$selectedLevels = array_intersect(
			$selectedLevels,
			array_keys( $thresholds )
		);

		if ( $selectedLevels ) {
			$ranges = [];
			foreach ( $selectedLevels as $level ) {
				$range = new Range(
					$thresholds[$level]['min'],
					$thresholds[$level]['max']
				);

				$result = array_filter(
					$ranges,
					function ( Range $r ) use ( $range ) {
						return $r->overlaps( $range );
					}
				);
				$overlap = reset( $result );
				if ( $overlap ) {
					/** @var Range $overlap */
					$overlap->combineWith( $range );
				} else {
					$ranges[] = $range;
				}
			}

			$betweenConditions = array_map(
				function ( Range $range ) use ( $name ) {
					$min = $range->getMin();
					$max = $range->getMax();
					return "ores_{$name}_cls.oresc_probability BETWEEN $min AND $max";
				},
				$ranges
			);

			return \wfGetDB( DB_REPLICA )->makeList( $betweenConditions, IDatabase::LIST_OR );
		}
	}

}
