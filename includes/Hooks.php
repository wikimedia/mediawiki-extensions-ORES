<?php

namespace ORES;

use BetaFeatures;
use ChangesList;
use ChangesListSpecialPage;
use ContribsPager;
use DatabaseUpdater;
use EnhancedChangesList;
use FormOptions;
use JobQueueGroup;
use Html;
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
	 * @return bool
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
		return true;
	}

	/**
	 * Ask the ORES server for scores on this recent change
	 *
	 * @param RecentChange $rc
	 * @return bool|null
	 */
	public static function onRecentChange_save( RecentChange $rc ) {
		global $wgOresExcludeBots, $wgOresEnabledNamespaces;
		if ( $rc->getAttribute( 'rc_bot' ) && $wgOresExcludeBots ) {
			return true;
		}

		// Check if we actually want score for this namespace
		$ns = $rc->getAttribute( 'rc_namespace' );
		if ( $wgOresEnabledNamespaces &&
			!( isset( $wgOresEnabledNamespaces[$ns] ) &&
			$wgOresEnabledNamespaces[$ns] )
		) {
			return true;
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

		return true;
	}

	/**
	 * Add an ORES filter to the recent changes results
	 *
	 * @param ChangesListSpecialPage $clsp
	 * @param $filters
	 * @return bool
	 */
	public static function onChangesListSpecialPageFilters(
		ChangesListSpecialPage $clsp,
		&$filters
	) {
		if ( !self::oresEnabled( $clsp->getUser() ) || !self::isModelEnabled( 'damaging' ) ) {
			return true;
		}

		switch ( $clsp->getName() ) {
			case 'Watchlist':
				$default = $clsp->getUser()->getOption( 'oresWatchlistHideNonDamaging' );
				break;
			case 'Recentchanges':
				$default = $clsp->getUser()->getOption( 'oresRCHideNonDamaging' );
				break;
			default:
				$default = false;
		}

		$filters['hidenondamaging'] = [
			'msg' => 'ores-damaging-filter',
			'default' => $default,
		];

		return true;
	}

	/**
	 * Pull in ORES score columns during recent changes queries
	 *
	 * @param string $name
	 * @param array $tables
	 * @param array $fields
	 * @param array $conds
	 * @param array $query_options
	 * @param array $join_conds
	 * @param FormOptions $opts
	 * @return bool
	 */
	public static function onChangesListSpecialPageQuery(
		$name, array &$tables, array &$fields, array &$conds,
		array &$query_options, array &$join_conds, FormOptions $opts
	) {
		global $wgUser;
		if ( !self::oresEnabled( $wgUser ) ) {
			return true;
		}

		$threshold = self::getDamagingThreshold( $wgUser );
		$dbr = \wfGetDB( DB_REPLICA );

		$tables['ores_damaging_mdl'] = 'ores_model';
		$tables['ores_damaging_cls'] = 'ores_classification';

		$fields['ores_damaging_score'] = 'ores_damaging_cls.oresc_probability';
		// Add user-based threshold
		$fields['ores_damaging_threshold'] = $dbr->addQuotes( $threshold );

		$join_conds['ores_damaging_mdl'] = [ 'LEFT JOIN', [
			'ores_damaging_mdl.oresm_is_current' => 1,
			'ores_damaging_mdl.oresm_name' => 'damaging'
		] ];
		$join_conds['ores_damaging_cls'] = [ 'LEFT JOIN', [
			'ores_damaging_cls.oresc_model = ores_damaging_mdl.oresm_id',
			'rc_this_oldid = ores_damaging_cls.oresc_rev',
			'ores_damaging_cls.oresc_class' => 1
		] ];

		if ( self::isModelEnabled( 'damaging' ) && $opts->getValue( 'hidenondamaging' ) ) {
			// Filter out non-damaging edits.
			$conds[] = 'ores_damaging_cls.oresc_probability > '
				. $dbr->addQuotes( $threshold );
			$conds['rc_patrolled'] = 0;

			// Performance hacks: add STRAIGHT_JOIN (146111) and override the LEFT JOINs
			// to be INNER JOINs (T137895)
			$query_options[] = 'STRAIGHT_JOIN';
			$join_conds['ores_damaging_mdl'][0] = 'INNER JOIN';
			$join_conds['ores_damaging_cls'][0] = 'INNER JOIN';
		}

		return true;
	}

	/**
	 * Label recent changes with ORES scores (for each change in an expanded group)
	 *
	 * @param EnhancedChangesList $ecl
	 * @param array $data
	 * @param RCCacheEntry[] $block
	 * @param RCCacheEntry $rcObj
	 * @param string[] $classes
	 * @return bool
	 */
	public static function onEnhancedChangesListModifyLineData(
		EnhancedChangesList $ecl,
		array &$data,
		array $block,
		RCCacheEntry $rcObj,
		array &$classes
	) {
		if ( !self::oresEnabled( $ecl->getUser() ) ) {
			return true;
		}

		self::processRecentChangesList( $rcObj, $data, $classes );

		return true;
	}

	/**
	 * Label recent changes with ORES scores (for top-level ungrouped lines)
	 *
	 * @param EnhancedChangesList $ecl
	 * @param array $data
	 * @param RCCacheEntry $rcObj
	 * @return bool
	 */
	public static function onEnhancedChangesListModifyBlockLineData(
		EnhancedChangesList $ecl,
		array &$data,
		RCCacheEntry $rcObj
	) {
		if ( !self::oresEnabled( $ecl->getUser() ) ) {
			return true;
		}

		self::processRecentChangesList( $rcObj, $data );

		return true;
	}

	/**
	 * Hook for formatting recent changes linkes
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/OldChangesListRecentChangesLine
	 *
	 * @param ChangesList $changesList
	 * @param string $s
	 * @param RecentChange $rc
	 * @param string[] &$classes
	 *
	 * @return bool
	 */
	public static function onOldChangesListRecentChangesLine(
		ChangesList &$changesList,
		&$s,
		$rc,
		&$classes = []
	) {
		if ( !self::oresEnabled( $changesList->getUser() ) ) {
			return true;
		}

		$damaging = self::getScoreRecentChangesList( $rc );
		if ( $damaging ) {
			$separator = ' <span class="mw-changeslist-separator">. .</span> ';
			if ( strpos( $s, $separator ) === false ) {
				return false;
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
	 * @return bool|null
	 */
	public static function onContribsGetQueryInfo(
		ContribsPager $pager,
		&$query
	) {
		if ( !self::oresEnabled( $pager->getUser() ) ) {
			return true;
		}

		$threshold = self::getDamagingThreshold( $pager->getUser() );
		$dbr = \wfGetDB( DB_REPLICA );

		$query['tables']['ores_damaging_mdl'] = 'ores_model';
		$query['tables']['ores_damaging_cls'] = 'ores_classification';

		$query['fields']['ores_damaging_score'] = 'ores_damaging_cls.oresc_probability';
		// Add user-based threshold
		$query['fields']['ores_damaging_threshold'] = $dbr->addQuotes( $threshold );

		$query['join_conds']['ores_damaging_mdl'] = [ 'LEFT JOIN', [
			'ores_damaging_mdl.oresm_is_current' => 1,
			'ores_damaging_mdl.oresm_name' => 'damaging',
		] ];

		$query['join_conds']['ores_damaging_cls'] = [ 'LEFT JOIN', [
			'ores_damaging_cls.oresc_model = ores_damaging_mdl.oresm_id',
			'rev_id = ores_damaging_cls.oresc_rev',
			'ores_damaging_cls.oresc_class' => 1
		] ];

		if (
			self::isModelEnabled( 'damaging' ) &&
			$pager->getContext()->getRequest()->getVal( 'hidenondamaging' )
		) {
			// Filter out non-damaging edits.
			$query['conds'][] = 'ores_damaging_cls.oresc_probability > '
				. $dbr->addQuotes( $threshold );

			// Performance hack: override the LEFT JOINs to be INNER JOINs (T137895)
			$query['join_conds']['ores_damaging_mdl'][0] = 'INNER JOIN';
			$query['join_conds']['ores_damaging_cls'][0] = 'INNER JOIN';
		}
		return true;
	}

	public static function onSpecialContributionsFormatRowFlags(
		RequestContext $context,
		$row,
		array &$flags
	) {
		if ( !self::oresEnabled( $context->getUser() ) ) {
			return true;
		}

		// Doesn't have ores score, skipping.
		if ( !isset( $row->ores_damaging_score ) ) {
			return true;
		}

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
			// Prepend the "r" flag
			array_unshift( $flags, ChangesList::flag( 'damaging' ) );
		}
		return true;
	}

	public static function onContributionsLineEnding(
		ContribsPager $pager,
		&$ret,
		$row,
		array &$classes
	) {
		if ( !self::oresEnabled( $pager->getUser() ) ) {
			return true;
		}

		// Doesn't have ores score, skipping.
		if ( !isset( $row->ores_damaging_score ) ) {
			return true;
		}

		if ( $row->ores_damaging_score > $row->ores_damaging_threshold ) {
			// Add the damaging class
			$classes[] = 'damaging';
		}
		return true;
	}

	/**
	 * Hook into Special:Contributions filters
	 *
	 * @param SpecialContributions $page
	 * @param string HTML[] $filters
	 * @return bool
	 */
	public static function onSpecialContributionsGetFormFilters(
		SpecialContributions $page,
		array &$filters
	) {
		if ( !self::oresEnabled( $page->getUser() ) || !self::isModelEnabled( 'damaging' ) ) {
			return true;
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

		return true;
	}

	/**
	 * Internal helper to label matching rows
	 *
	 * @param RCCacheEntry $rcObj
	 * @param string[]
	 * @param string[]
	 */
	protected static function processRecentChangesList(
		RCCacheEntry $rcObj,
		array &$data,
		array &$classes = []
	) {
		$damaging = self::getScoreRecentChangesList( $rcObj );
		if ( $damaging ) {
			$classes[] = 'damaging';
			$data['recentChangesFlags']['damaging'] = true;
		}
	}

	/**
	 * Check if we should flag a row
	 * @param RecentChange $rcObj
	 * @return bool
	 */
	public static function getScoreRecentChangesList( $rcObj ) {
		global $wgUser;
		$threshold = $rcObj->getAttribute( 'ores_damaging_threshold' );
		if ( $threshold === null ) {
			$threshold = self::getDamagingThreshold( $wgUser );
		}
		$score = $rcObj->getAttribute( 'ores_damaging_score' );
		$patrolled = $rcObj->getAttribute( 'rc_patrolled' );

		return $score && $score >= $threshold && !$patrolled;
	}

	/**
	 * Internal helper to get threshold
	 * @param User $user
	 * @return int
	 */
	public static function getDamagingThreshold( User $user ) {
		global $wgOresDamagingThresholds;

		$pref = $user->getOption( 'oresDamagingPref' );

		return $wgOresDamagingThresholds[$pref];
	}

	/**
	 * GetPreferences hook, adding ORES section, letting people choose a threshold
	 * Also let people make hidenondamaging default
	 *
	 * @param User $user
	 * @param string[] $preferences
	 * @return bool
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		global $wgOresDamagingThresholds;

		if ( !self::oresEnabled( $user ) || !self::isModelEnabled( 'damaging' ) ) {
			return true;
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

		return true;
	}

	/**
	 * Add CSS styles to output page
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		if ( !self::oresEnabled( $out->getUser() ) ) {
			return true;
		}
		$out->addModuleStyles( 'ext.ores.styles' );
		return true;
	}

	/**
	 * Make a beta feature
	 *
	 * @param User $user
	 * @param string[]
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
		return BetaFeatures::isFeatureEnabled( $user, 'ores-enabled' );
	}

	/**
	 * Check whether a given model is enabled in the config
	 * @param string $model
	 * @return bool
	 */
	private static function isModelEnabled( $model ) {
		global $wgOresModels;
		return isset( $wgOresModels[$model] ) && $wgOresModels[$model];
	}
}
