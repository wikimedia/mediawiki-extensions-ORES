<?php

namespace ORES;

use BetaFeatures;
use ChangesList;
use ChangesListBooleanFilterGroup;
use ChangesListFilterGroup;
use ChangesListSpecialPage;
use ChangesListStringOptionsFilterGroup;
use ContribsPager;
use DatabaseUpdater;
use EnhancedChangesList;
use Exception;
use FormOptions;
use JobQueueGroup;
use Html;
use IContextSource;
use MediaWiki\Logger\LoggerFactory;
use OutputPage;
use RCCacheEntry;
use RecentChange;
use RequestContext;
use Skin;
use SpecialContributions;
use SpecialRecentChanges;
use SpecialWatchlist;
use Title;
use User;
use Xml;

class Hooks {
	// The oresDamagingPref preference uses these names for historical reasons
	// TODO: Move to a better place
	public static $damagingPrefMap = [
		'hard' => 'maybebad',
		'soft' => 'likelybad',
		'softest' => 'verylikelybad',
	];

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'ores_classification', __DIR__ . '/../sql/ores_classification.sql' );
		$updater->addExtensionTable( 'ores_model', __DIR__ . '/../sql/ores_model.sql' );
		$updater->dropExtensionIndex( 'ores_classification', 'oresc_rev',
			__DIR__ . '/../sql/patch-ores-classification-indexes.sql' );
		$updater->addExtensionIndex( 'ores_classification', 'oresc_rev_model_class',
			__DIR__ . '/../sql/patch-ores-classification-unique-indexes.sql' );
		$updater->addExtensionIndex( 'ores_model', 'oresm_model_status',
			__DIR__ . '/../sql/patch-ores-model-indexes.sql' );
	}

	/**
	 * Ask the ORES server for scores on this recent change
	 *
	 * @param RecentChange $rc
	 */
	public static function onRecentChange_save( RecentChange $rc ) {
		global $wgOresExcludeBots, $wgOresEnabledNamespaces;
		if ( $rc->getAttribute( 'rc_bot' ) && $wgOresExcludeBots ) {
			return;
		}

		// Check if we actually want score for this namespace
		$ns = $rc->getAttribute( 'rc_namespace' );
		if ( $wgOresEnabledNamespaces &&
			!( isset( $wgOresEnabledNamespaces[$ns] ) &&
			$wgOresEnabledNamespaces[$ns] )
		) {
			return;
		}

		$rc_type = $rc->getAttribute( 'rc_type' );
		if ( $rc_type === RC_EDIT || $rc_type === RC_NEW ) {
			$revid = $rc->getAttribute( 'rc_this_oldid' );
			$logger = LoggerFactory::getInstance( 'ORES' );
			$logger->debug( 'Processing edit {revid}', [
				'revid' => $revid,
			] );
			$request = RequestContext::getMain()->getRequest();
			$job = new FetchScoreJob( $rc->getTitle(), [
				'revid' => $revid,
				'originalRequest' => [
					'ip' => $request->getIP(),
					'userAgent' => $request->getHeader( 'User-Agent' ),
				],
				'extra_params' => [ 'precache' => 'true' ],
			] );
			JobQueueGroup::singleton()->push( $job );
			$logger->debug( 'Job pushed for {revid}', [
				'revid' => $revid,
			] );
		}
	}

	public static function onChangesListSpecialPageStructuredFilters(
		ChangesListSpecialPage $clsp
	) {
		// ORES is disabled on Recentchangeslinked: T163063
		if ( !self::oresUiEnabled( $clsp->getUser() ) || $clsp->getName() === 'Recentchangeslinked' ) {
			return;
		}

		$stats = Stats::newFromGlobalState();

		$changeTypeGroup = $clsp->getFilterGroup( 'changeType' );
		$logFilter = $changeTypeGroup->getFilter( 'hidelog' );

		if ( self::isModelEnabled( 'damaging' ) ) {
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
					'queryCallable' => function ( $specialClassName, $ctx, $dbr, &$tables, &$fields,
							&$conds, &$query_options, &$join_conds, $selectedValues ) {
						$condition = self::buildRangeFilter( 'damaging', $selectedValues );
						if ( $condition ) {
							$conds[] = $condition;

							// Filter out incompatible types; log actions and external rows are not scorable
							$conds[] = 'rc_type NOT IN (' . $dbr->makeList( [ RC_LOG, RC_EXTERNAL ] ) . ')';
							// Make the joins INNER JOINs instead of LEFT JOINs
							$join_conds['ores_damaging_mdl'][0] = 'INNER JOIN';
							$join_conds['ores_damaging_cls'][0] = 'INNER JOIN';
							// Performance hack: add STRAIGHT_JOIN (146111)
							$query_options[] = 'STRAIGHT_JOIN';
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

				$clsp->registerFilterGroup( $newDamagingGroup );
			}

			if ( $clsp instanceof SpecialRecentChanges ) {
				$damagingDefault = $clsp->getUser()->getOption( 'oresRCHideNonDamaging' );
			} elseif ( $clsp instanceof SpecialWatchlist ) {
				$damagingDefault = $clsp->getUser()->getOption( 'oresWatchlistHideNonDamaging' );
			} else {
				$damagingDefault = false;
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
							self::hideNonDamagingFilter( $fields, $conds, true, $ctx->getUser() );
							// Filter out incompatible types; log actions and external rows are not scorable
							$conds[] = 'rc_type NOT IN (' . $dbr->makeList( [ RC_LOG, RC_EXTERNAL ] ) . ')';
							// Filter out patrolled edits: the 'r' doesn't appear for them
							$conds['rc_patrolled'] = 0;
							// Make the joins INNER JOINs instead of LEFT JOINs
							$join_conds['ores_damaging_mdl'][0] = 'INNER JOIN';
							$join_conds['ores_damaging_cls'][0] = 'INNER JOIN';
							// Performance hack: add STRAIGHT_JOIN (146111)
							$query_options[] = 'STRAIGHT_JOIN';
						},
					]
				],

			] );

			$clsp->registerFilterGroup( $legacyDamagingGroup );
		}
		if ( self::isModelEnabled( 'goodfaith' ) ) {
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
							// Performance hack: add STRAIGHT_JOIN (146111)
							$query_options[] = 'STRAIGHT_JOIN';
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
		if ( !self::oresUiEnabled( $wgUser ) || $name === 'Recentchangeslinked' ) {
			return;
		}

		if ( self::isModelEnabled( 'damaging' ) ) {
			self::joinWithOresTables(
				'damaging',
				'rc_this_oldid',
				$tables,
				$fields,
				$join_conds
			);
		}
		if ( self::isModelEnabled( 'goodfaith' ) ) {
			self::joinWithOresTables(
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
		if ( !self::oresUiEnabled( $ecl->getUser() ) ) {
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
		if ( !self::oresUiEnabled( $ecl->getUser() ) ) {
			return;
		}

		$classes = [];
		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );
		$data['attribs']['class'] = array_merge( $data['attribs']['class'], $classes );
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
		$rc,
		&$classes = []
	) {
		if ( !self::oresUiEnabled( $changesList->getUser() ) ) {
			return;
		}

		$damaging = self::getScoreRecentChangesList( $rc, $changesList->getContext() );
		if ( $damaging ) {
			// Add highlight class
			if ( self::isHighlightEnabled( $changesList ) ) {
				$classes[] = 'ores-highlight';
			}

			// Add damaging class and flag
			if ( self::isDamagingFlagEnabled( $changesList ) ) {
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

		return true;
	}

	/**
	 * Filter out non-damaging changes from Special:Contributions
	 *
	 * @param ContribsPager $pager
	 * @param array &$query
	 */
	public static function onContribsGetQueryInfo(
		ContribsPager $pager,
		&$query
	) {
		if ( !self::oresUiEnabled( $pager->getUser() ) ) {
			return;
		}

		if ( self::isModelEnabled( 'damaging' ) ) {
			$request = $pager->getContext()->getRequest();

			self::joinWithOresTables(
				'damaging',
				'rev_id',
				$query['tables'],
				$query['fields'],
				$query['join_conds']
			);

			self::hideNonDamagingFilter(
				$query['fields'],
				$query['conds'],
				$request->getVal( 'hidenondamaging' ),
				$pager->getUser()
			);
		}
	}

	public static function onSpecialContributionsFormatRowFlags(
		RequestContext $context,
		$row,
		array &$flags
	) {
		if ( !self::oresUiEnabled( $context->getUser() ) ) {
			return;
		}

		// Doesn't have ores score, skipping.
		if ( !isset( $row->ores_damaging_score ) ) {
			return;
		}

		self::addRowData( $context, $row->rev_id, (float)$row->ores_damaging_score, 'damaging' );

		if (
			self::isDamagingFlagEnabled( $context ) &&
			$row->ores_damaging_score > $row->ores_damaging_threshold
		) {
			// Prepend the "r" flag
			array_unshift( $flags, ChangesList::flag( 'damaging' ) );
		}
	}

	public static function onContributionsLineEnding(
		ContribsPager $pager,
		&$ret,
		$row,
		array &$classes
	) {
		if ( !self::oresUiEnabled( $pager->getUser() ) ) {
			return;
		}

		// Doesn't have ores score or threshold is not set properly, skipping.
		if ( !isset( $row->ores_damaging_score ) || !isset( $row->ores_damaging_threshold ) ) {
			return;
		}

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
			if ( self::isHighlightEnabled( $pager ) ) {
				$classes[] = 'ores-highlight';
			}
			if ( self::isDamagingFlagEnabled( $pager ) ) {
				$classes[] = 'damaging';
			}
		}
	}

	/**
	 * Hook into Special:Contributions filters
	 *
	 * @param SpecialContributions $page
	 * @param string[] &$filters HTML
	 */
	public static function onSpecialContributionsGetFormFilters(
		SpecialContributions $page,
		array &$filters
	) {
		if ( !self::oresUiEnabled( $page->getUser() ) || !self::isModelEnabled( 'damaging' ) ) {
			return;
		}

		$filters[] = Html::rawElement(
			'span',
			[ 'class' => 'mw-input-with-label' ],
			Xml::checkLabel(
				$page->msg( 'ores-hide-nondamaging-filter' )->text(),
				'hidenondamaging',
				'ores-hide-nondamaging',
				$page->getContext()->getRequest()->getVal( 'hidenondamaging' ),
				[ 'class' => 'mw-input' ]
			)
		);
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

		if ( $damaging && self::isDamagingFlagEnabled( $context ) ) {
			$classes[] = 'damaging';
			$data['recentChangesFlags']['damaging'] = true;
		}
	}

	/**
	 * Check if we should flag a row. As a side effect, also adds score data for this row.
	 * @param RecentChange $rcObj
	 * @param IContextSource $context
	 * @return bool
	 */
	public static function getScoreRecentChangesList( $rcObj, IContextSource $context ) {
		global $wgUser;
		$threshold = $rcObj->getAttribute( 'ores_damaging_threshold' );
		if ( $threshold === null ) {
			$threshold = self::getThreshold( 'damaging', $wgUser );
		}
		$score = $rcObj->getAttribute( 'ores_damaging_score' );
		$patrolled = $rcObj->getAttribute( 'rc_patrolled' );
		$type = $rcObj->getAttribute( 'rc_type' );

		// Log actions and external rows are not scorable; if such a row does have a score, ignore it
		if ( !$score || $threshold === null || in_array( $type, [ RC_LOG, RC_EXTERNAL ] ) ) {
			// Shorten out
			return false;
		}

		self::addRowData(
			$context,
			$rcObj->getAttribute( 'rc_this_oldid' ),
			(float)$score,
			'damaging'
		);

		return $score && $score >= $threshold && !$patrolled;
	}

	/**
	 * Internal helper to get threshold
	 * @param string $type
	 * @param User $user
	 * @return float|null Threshold, or null if not set
	 * @throws Exception When $type is not recognized
	 */
	public static function getThreshold( $type, User $user ) {
		if ( $type === 'damaging' ) {
			$pref = $user->getOption( 'oresDamagingPref' );
			if ( isset( self::$damagingPrefMap[ $pref ] ) ) {
				$pref = self::$damagingPrefMap[ $pref ];
			}
			$thresholds = self::getDamagingThresholds();
			if ( isset( $thresholds[ $pref ] ) ) {
				return $thresholds[ $pref ];
			}
			return null;
		}
		throw new Exception( "Unknown ORES test: '$type'" );
	}

	/**
	 * Add CSS styles to output page
	 *
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		if ( !self::oresUiEnabled( $out->getUser() ) ) {
			return;
		}

		$oresData = $out->getProperty( 'oresData' );

		if ( $oresData !== null ) {
			$out->addJsConfigVars( 'oresData', $oresData );
			$out->addJsConfigVars(
				'oresThresholds',
				[ 'damaging' => self::getDamagingThresholds() ]
			);
			$out->addModuleStyles( 'ext.ores.styles' );
			if ( self::isHighlightEnabled( $out ) ) {
				$out->addModules( 'ext.ores.highlighter' );
			}
		}
	}

	public static function getDamagingThresholds() {
		$stats = Stats::newFromGlobalState();
		$thresholds = [];
		foreach ( $stats->getThresholds( 'damaging' ) as $name => $bounds ) {
			$thresholds[ $name ] = $bounds[ 'min' ];
		}
		unset( $thresholds[ 'likelygood' ] );
		return $thresholds;
	}

	/**
	 * Make a beta feature
	 *
	 * @param User $user
	 * @param string[] &$prefs
	 */
	public static function onGetBetaFeaturePreferences( User $user, array &$prefs ) {
		global $wgOresExtensionStatus, $wgExtensionAssetsPath;

		if ( $wgOresExtensionStatus === 'beta' ) {
			$prefs['ores-enabled'] = [
				'label-message' => 'ores-beta-feature-message',
				'desc-message' => 'ores-beta-feature-description',
				'screenshot' => [
					'ltr' => "$wgExtensionAssetsPath/ORES/images/ORES-beta-features-ltr.svg",
					'rtl' => "$wgExtensionAssetsPath/ORES/images/ORES-beta-features-rtl.svg",
				],
				'info-link' => 'https://www.mediawiki.org/wiki/ORES_review_tool',
				'discussion-link' => 'https://www.mediawiki.org/wiki/Talk:ORES_review_tool',
			];
		}
	}

	/**
	 * Check whether ores is enabled
	 *
	 * @param User $user
	 * @return bool
	 */
	public static function oresUiEnabled( User $user ) {
		global $wgOresExtensionStatus, $wgOresUiEnabled;

		// Is the UI enabled or not?  If not, we've been deployed in
		// infrastructure-only mode, so hide all the UI elements.
		if ( !$wgOresUiEnabled ) {
			return false;
		}

		// enabled by default for everybody
		if ( $wgOresExtensionStatus === 'on' ) {
			return true;
		}

		// exists as a beta feature, enabled by $user
		if ( $wgOresExtensionStatus === 'beta' ) {
			return $user &&
				$user->isLoggedIn() &&
				class_exists( BetaFeatures::class ) &&
				BetaFeatures::isFeatureEnabled( $user, 'ores-enabled' );
		}

		return false;
	}

	/**
	 * @param IContextSource $context
	 * @return bool Whether $context->getTitle() is a RecentChanges page
	 */
	private static function isRCPage( IContextSource $context ) {
		return $context->getTitle()->isSpecial( 'Recentchanges' ) ||
			$context->getTitle()->isSpecial( 'Recentchangeslinked' );
	}

	/**
	 * @param IContextSource $title
	 * @return bool Whether highlights should be shown
	 */
	private static function isHighlightEnabled( IContextSource $context ) {
		// Was previously controlled by different preferences than the "r", but they're currently
		// the same.
		return self::isDamagingFlagEnabled( $context );
	}

	/**
	 * @param IContextSource $context
	 * @return bool Whether the damaging flag ("r") should be shown
	 */
	private static function isDamagingFlagEnabled( IContextSource $context ) {
		global $wgOresExtensionStatus;
		$isRCPage = self::isRCPage( $context );
		$user = $context->getUser();
		return $wgOresExtensionStatus === 'beta' ||
			(
				$isRCPage &&
				$user->getBoolOption( 'ores-damaging-flag-rc' ) &&
				// If rcenhancedfilters is enabled, the ores-damaging-flag-rc preference is hidden,
				// but it doesn't behave as if it's false; see HACK comment in onGetPreferences
				!$user->getBoolOption( 'rcenhancedfilters' )
			) || (
				!$isRCPage &&
				$user->getBoolOption( 'oresHighlight' )
			);
	}

	/**
	 * Check whether a given model is enabled in the config
	 * @param string $model
	 * @return bool
	 */
	public static function isModelEnabled( $model ) {
		global $wgOresModels;
		return isset( $wgOresModels[$model] ) && $wgOresModels[$model];
	}

	/**
	 * @param IContextSource $context
	 * @param int $revisionId
	 * @param float $score
	 * @param string $model
	 */
	private static function addRowData( IContextSource $context, $revisionId, $score, $model ) {
		$out = $context->getOutput();
		$data = $out->getProperty( 'oresData' );
		if ( !isset( $data[$revisionId] ) ) {
			$data[$revisionId] = [];
		}
		$data[$revisionId][$model] = $score;
		$out->setProperty( 'oresData', $data );
	}

	private static function joinWithOresTables(
		$type,
		$revIdField,
		array &$tables,
		array &$fields,
		array &$join_conds
	) {
		if ( !ctype_lower( $type ) ) {
			throw new Exception(
				"Invalid value for parameter 'type': '$type'. " .
				'Restricted to one lower case word to prevent accidental injection.'
			);
		}

		$tables["ores_${type}_mdl"] = 'ores_model';
		$tables["ores_${type}_cls"] = 'ores_classification';

		$fields["ores_${type}_score"] = "ores_${type}_cls.oresc_probability";

		$join_conds["ores_${type}_mdl"] = [ 'LEFT JOIN', [
			"ores_${type}_mdl.oresm_is_current" => 1,
			"ores_${type}_mdl.oresm_name" => $type,
		] ];
		$join_conds["ores_${type}_cls"] = [ 'LEFT JOIN', [
			"ores_${type}_cls.oresc_model = ores_${type}_mdl.oresm_id",
			"$revIdField = ores_${type}_cls.oresc_rev",
			"ores_${type}_cls.oresc_class" => 1
		] ];
	}

	private static function hideNonDamagingFilter(
		array &$fields,
		array &$conds,
		$hidenondamaging,
		$user
	) {
		$dbr = \wfGetDB( DB_REPLICA );
		// Add user-based threshold
		$threshold = self::getThreshold( 'damaging', $user );
		if ( $threshold === null ) {
			return;
		}
		// FIXME: This is not a "filter" but an undocumented side effect of this function.
		$fields['ores_damaging_threshold'] = $dbr->addQuotes( $threshold );

		if ( $hidenondamaging ) {
			// Filter out non-damaging edits.
			$conds[] = 'ores_damaging_cls.oresc_probability > ' . $dbr->addQuotes( $threshold );
		}
	}

	private static function buildRangeFilter( $name, $filterValue ) {
		$stats = Stats::newFromGlobalState();
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

			return \wfGetDB( DB_REPLICA )->makeList( $betweenConditions, \IDatabase::LIST_OR );
		}
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

}
