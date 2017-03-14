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
use User;
use Xml;

class Hooks {

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
			$job = new FetchScoreJob( $rc->getTitle(), [
				'revid' => $revid,
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
		if ( !self::oresEnabled( $clsp->getUser() ) ) {
			return;
		}

		$stats = Stats::newFromGlobalState();
		if ( self::isModelEnabled( 'damaging' ) ) {
			$damagingLevels = $stats->getThresholds( 'damaging' );
			$newDamagingGroup = new ChangesListStringOptionsFilterGroup( [
				'name' => 'damaging',
				'title' => 'ores-rcfilters-damaging-title',
				'priority' => 2,
				'filters' => [
					[
						'name' => 'likelygood',
						'label' => 'ores-rcfilters-damaging-likelygood-label',
						'description' => 'ores-rcfilters-damaging-likelygood-desc',
						'cssClassSuffix' => 'damaging-likelygood',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'damaging',
							$damagingLevels['likelygood']
						),
					],
					[
						'name' => 'maybebad',
						'label' => 'ores-rcfilters-damaging-maybebad-label',
						'description' => 'ores-rcfilters-damaging-maybebad-desc',
						'cssClassSuffix' => 'damaging-maybebad',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'damaging',
							$damagingLevels['maybebad']
						),
					],
					[
						'name' => 'likelybad',
						'label' => 'ores-rcfilters-damaging-likelybad-label',
						'description' => 'ores-rcfilters-damaging-likelybad-desc',
						'cssClassSuffix' => 'damaging-likelybad',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'damaging',
							$damagingLevels['likelybad']
						),
					],
					[
						'name' => 'verylikelybad',
						'label' => 'ores-rcfilters-damaging-verylikelybad-label',
						'description' => 'ores-rcfilters-damaging-verylikelybad-desc',
						'cssClassSuffix' => 'damaging-verylikelybad',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'damaging',
							$damagingLevels['verylikelybad']
						),
					],
				],
				'default' => ChangesListStringOptionsFilterGroup::NONE,
				'isFullCoverage' => false,
				'queryCallable' => function ( $specialClassName, $ctx, $dbr, &$tables, &$fields,
						&$conds, &$query_options, &$join_conds, $selectedValues ) {
					$condition = self::buildRangeFilter( 'damaging', $selectedValues );
					if ( $condition ) {
						$conds[] = $condition;
						$join_conds['ores_damaging_mdl'][0] = 'INNER JOIN';
						$join_conds['ores_damaging_cls'][0] = 'INNER JOIN';
						// Performance hack: add STRAIGHT_JOIN (146111)
						$query_options[] = 'STRAIGHT_JOIN';
					}
				},
			] );
			$newDamagingGroup->getFilter( 'maybebad' )->setAsSupersetOf(
				$newDamagingGroup->getFilter( 'likelybad' )
			);
			$newDamagingGroup->getFilter( 'likelybad' )->setAsSupersetOf(
				$newDamagingGroup->getFilter( 'verylikelybad' )
			);
			// Transitive closure
			$newDamagingGroup->getFilter( 'maybebad' )->setAsSupersetOf(
				$newDamagingGroup->getFilter( 'verylikelybad' )
			);
			$clsp->registerFilterGroup( $newDamagingGroup );

			if ( $clsp->getName() === 'Recentchanges' ) {
				$damagingDefault = $clsp->getUser()->getOption( 'oresRCHideNonDamaging' );
			} elseif ( $clsp->getName() === 'Watchlist' ) {
				$damagingDefault = $clsp->getUser()->getOption( 'oresWatchlistHideNonDamaging' );
			} else {
				$damagingDefault = false;
			}

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
							$conds['rc_patrolled'] = 0;
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
			$goodfaithGroup = new ChangesListStringOptionsFilterGroup( [
				'name' => 'goodfaith',
				'title' => 'ores-rcfilters-goodfaith-title',
				'priority' => 1,
				'filters' => [
					[
						'name' => 'good',
						'label' => 'ores-rcfilters-goodfaith-good-label',
						'description' => 'ores-rcfilters-goodfaith-good-desc',
						'cssClassSuffix' => 'goodfaith-good',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'goodfaith',
							$goodfaithLevels['good']
						),
					],
					[
						// HACK the front-end doesn't support StringOptionsFilters with the same name
						'name' => 'maybebadfaith',
						'label' => 'ores-rcfilters-goodfaith-maybebad-label',
						'description' => 'ores-rcfilters-goodfaith-maybebad-desc',
						'cssClassSuffix' => 'goodfaith-maybebad',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'goodfaith',
							$goodfaithLevels['maybebad']
						),
					],
					[
						'name' => 'bad',
						'label' => 'ores-rcfilters-goodfaith-bad-label',
						'description' => 'ores-rcfilters-goodfaith-bad-desc',
						'cssClassSuffix' => 'goodfaith-bad',
						'isRowApplicableCallable' => self::makeApplicableCallback(
							'goodfaith',
							$goodfaithLevels['bad']
						),
					],
				],
				'default' => ChangesListStringOptionsFilterGroup::NONE,
				'isFullCoverage' => false,
				'queryCallable' => function ( $specialClassName, $ctx, $dbr, &$tables, &$fields,
						&$conds, &$query_options, &$join_conds, $selectedValues ) {
					$condition = self::buildRangeFilter( 'goodfaith', $selectedValues );
					if ( $condition ) {
						$conds[] = $condition;
						$join_conds['ores_goodfaith_mdl'][0] = 'INNER JOIN';
						$join_conds['ores_goodfaith_cls'][0] = 'INNER JOIN';
						// Performance hack: add STRAIGHT_JOIN (146111)
						$query_options[] = 'STRAIGHT_JOIN';
					}
				},
			] );
			$goodfaithGroup->getFilter( 'maybebadfaith' )->setAsSupersetOf(
				$goodfaithGroup->getFilter( 'bad' )
			);
			$clsp->registerFilterGroup( $goodfaithGroup );
		}
	}

	public static function onChangesListSpecialPageQuery(
		$name, array &$tables, array &$fields, array &$conds,
		array &$query_options, array &$join_conds, FormOptions $opts
	) {
		global $wgUser;

		if ( !self::oresEnabled( $wgUser ) ) {
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
	 * @param array $data
	 * @param RCCacheEntry[] $block
	 * @param RCCacheEntry $rcObj
	 * @param string[] $classes
	 */
	public static function onEnhancedChangesListModifyLineData(
		EnhancedChangesList $ecl,
		array &$data,
		array $block,
		RCCacheEntry $rcObj,
		array &$classes
	) {
		if ( !self::oresEnabled( $ecl->getUser() ) ) {
			return;
		}

		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );
	}

	/**
	 * Label recent changes with ORES scores (for top-level ungrouped lines)
	 *
	 * @param EnhancedChangesList $ecl
	 * @param array $data
	 * @param RCCacheEntry $rcObj
	 */
	public static function onEnhancedChangesListModifyBlockLineData(
		EnhancedChangesList $ecl,
		array &$data,
		RCCacheEntry $rcObj
	) {
		if ( !self::oresEnabled( $ecl->getUser() ) ) {
			return;
		}

		$classes = [];
		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );
	}

	/**
	 * Hook for formatting recent changes linkes
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/OldChangesListRecentChangesLine
	 *
	 * @param ChangesList $changesList
	 * @param string $s
	 * @param RecentChange $rc
	 * @param string[] &$classes
	 */
	public static function onOldChangesListRecentChangesLine(
		ChangesList &$changesList,
		&$s,
		$rc,
		&$classes = []
	) {
		if ( !self::oresEnabled( $changesList->getUser() ) ) {
			return;
		}

		$damaging = self::getScoreRecentChangesList( $rc, $changesList->getContext() );
		if ( $damaging ) {
			$separator = ' <span class="mw-changeslist-separator">. .</span> ';
			if ( strpos( $s, $separator ) === false ) {
				return;
			}

			$classes[] = 'damaging';
			$parts = explode( $separator, $s );
			$parts[1] = ChangesList::flag( 'damaging' ) . $parts[1];
			$s = implode( $separator, $parts );
		}

		return true;
	}

	/**
	 * Filter out non-damaging changes from Special:Contributions
	 *
	 * @param ContribsPager $pager
	 * @param array $query
	 */
	public static function onContribsGetQueryInfo(
		ContribsPager $pager,
		&$query
	) {
		if ( !self::oresEnabled( $pager->getUser() ) ) {
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
		if ( !self::oresEnabled( $context->getUser() ) ) {
			return;
		}

		// Doesn't have ores score, skipping.
		if ( !isset( $row->ores_damaging_score ) ) {
			return;
		}

		self::addRowData( $context, $row->rev_id, (float)$row->ores_damaging_score, 'damaging' );

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
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
		if ( !self::oresEnabled( $pager->getUser() ) ) {
			return;
		}

		// Doesn't have ores score, skipping.
		if ( !isset( $row->ores_damaging_score ) ) {
			return;
		}

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
			// Add the damaging class
			$classes[] = 'damaging';
		}
	}

	/**
	 * Hook into Special:Contributions filters
	 *
	 * @param SpecialContributions $page
	 * @param string HTML[] $filters
	 */
	public static function onSpecialContributionsGetFormFilters(
		SpecialContributions $page,
		array &$filters
	) {
		if ( !self::oresEnabled( $page->getUser() ) || !self::isModelEnabled( 'damaging' ) ) {
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
		if ( $damaging ) {
			$classes[] = 'damaging';
			$data['recentChangesFlags']['damaging'] = true;
		}
	}

	/**
	 * Check if we should flag a row
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

		if ( !$score ) {
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
	 * @return float Threshold
	 * @throws Exception When $type is not recognized
	 */
	public static function getThreshold( $type, User $user ) {
		global $wgOresDamagingThresholds;
		if ( $type === 'damaging' ) {
			$pref = $user->getOption( 'oresDamagingPref' );
			return $wgOresDamagingThresholds[$pref];
		}
		throw new Exception( "Unknown ORES test: '$type'" );
	}

	/**
	 * GetPreferences hook, adding ORES section, letting people choose a threshold
	 * Also let people make hidenondamaging default
	 *
	 * @param User $user
	 * @param string[] $preferences
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		global $wgOresDamagingThresholds;

		if ( !self::oresEnabled( $user ) || !self::isModelEnabled( 'damaging' ) ) {
			return;
		}

		$options = [];
		foreach ( $wgOresDamagingThresholds as $case => $value ) {
			$text = \wfMessage( 'ores-damaging-' . $case )->parse();
			$options[$text] = $case;
		}
		$preferences['oresDamagingPref'] = [
			'type' => 'select',
			'label-message' => 'ores-pref-damaging',
			'section' => 'rc/ores',
			'options' => $options,
			'help-message' => 'ores-help-damaging-pref',
		];

		// Make hidenondamaging default
		$preferences['oresWatchlistHideNonDamaging'] = [
			'type' => 'toggle',
			'section' => 'watchlist/ores',
			'label-message' => 'ores-pref-watchlist-hidenondamaging',
		];
		$preferences['oresRCHideNonDamaging'] = [
			'type' => 'toggle',
			'section' => 'rc/advancedrc',
			'label-message' => 'ores-pref-rc-hidenondamaging',
		];
	}

	/**
	 * Add CSS styles to output page
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgOresDamagingThresholds;
		if ( !self::oresEnabled( $out->getUser() ) ) {
			return;
		}

		$oresData = $out->getProperty( 'oresData' );

		if ( $oresData !== null ) {
			$out->addJsConfigVars( 'oresData', $oresData );
			$out->addJsConfigVars(
				'oresThresholds',
				[ 'damaging' => $wgOresDamagingThresholds ]
			);
			$out->addModules( 'ext.ores.highlighter' );
			$out->addModuleStyles( 'ext.ores.styles' );
		}
	}

	/**
	 * Make a beta feature
	 *
	 * @param User $user
	 * @param string[] &$prefs
	 */
	public static function onGetBetaFeaturePreferences( User $user, array &$prefs ) {
		global $wgExtensionAssetsPath;

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

	/**
	 * Check whether the user enabled ores as a beta feature
	 *
	 * @param User $user
	 * @return bool
	 */
	private static function oresEnabled( User $user ) {
		if ( !class_exists( 'BetaFeatures' ) ) {
			return false;
		}
		return BetaFeatures::isFeatureEnabled( $user, 'ores-enabled' );
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
			if ( $score === null ) {
				return false;
			}
			return $levelData['min'] <= $score && $score <= $levelData['max'];
		};
	}

}
