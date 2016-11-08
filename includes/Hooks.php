<?php

namespace ORES;

use BetaFeatures;
use ChangesList;
use ChangesListSpecialPage;
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

		if ( self::isModelEnabled( 'damaging' ) ) {
			$hidenondamaging = $opts->getValue( 'hidenondamaging' );
			self::manipulateQuery(
				'damaging',
				$wgUser,
				'rc_this_oldid',
				$hidenondamaging,
				$tables,
				$fields,
				$conds,
				$query_options,
				$join_conds
			);

			if ( $hidenondamaging ) {
				$conds['rc_patrolled'] = 0;
				// Performance hack: add STRAIGHT_JOIN (146111)
				$query_options[] = 'STRAIGHT_JOIN';
			}
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

		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );

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

		$classes = [];
		self::processRecentChangesList( $rcObj, $data, $classes, $ecl->getContext() );

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

		$damaging = self::getScoreRecentChangesList( $rc, $changesList->getContext() );
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
		$request = $pager->getContext()->getRequest();
		self::manipulateQuery(
			'damaging',
			$pager->getUser(),
			'rev_id',
			$request->getVal( 'hidenondamaging' ),
			$query['tables'],
			$query['fields'],
			$query['conds'],
			$query['options'],
			$query['join_conds']
		);
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

		self::addRowData( $context, $row->rev_id, (float)$row->ores_damaging_score, 'damaging' );

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
		global $wgOresDamagingThresholds;
		if ( !self::oresEnabled( $out->getUser() ) ) {
			return true;
		}

		$oresData = $out->getProperty( 'oresData' );

		if ( $oresData !== null ) {
			$out->addJsConfigVars( 'oresData', $oresData );
			$out->addJsConfigVars(
				'oresThresholds',
				[ 'damaging' => $wgOresDamagingThresholds ]
			);
			$out->addModules( 'ext.ores.highlight' );
		}
		return true;
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

	private static function manipulateQuery(
		$type,
		User $user,
		$revid_field,
		$filter,
		array &$tables,
		array &$fields,
		array &$conds,
		array &$query_options,
		array &$join_conds
	) {
		if ( !self::isModelEnabled( $type ) ) {
			return;
		}

		$dbr = \wfGetDB( DB_REPLICA );
		$threshold = self::getThreshold( $type, $user );
		$tables["ores_${type}_mdl"] = 'ores_model';
		$tables["ores_${type}_cls"] = 'ores_classification';

		$fields["ores_${type}_score"] = "ores_${type}_cls.oresc_probability";
		// Add user-based threshold
		$fields["ores_${type}_threshold"] = $dbr->addQuotes( $threshold );

		$join_conds["ores_${type}_mdl"] = [ 'LEFT JOIN', [
			"ores_${type}_mdl.oresm_is_current" => 1,
			"ores_${type}_mdl.oresm_name" => $type,
		] ];
		$join_conds["ores_${type}_cls"] = [ 'LEFT JOIN', [
			"ores_${type}_cls.oresc_model = ores_${type}_mdl.oresm_id",
			"$revid_field = ores_${type}_cls.oresc_rev",
			"ores_${type}_cls.oresc_class" => 1
		] ];

		if ( $filter ) {
			// Filter out non-damaging edits.
			$conds[] = "ores_${type}_cls.oresc_probability > " . $dbr->addQuotes( $threshold );
			// Performance hack: override the LEFT JOINs to be INNER JOINs (T137895)
			$join_conds["ores_${type}_mdl"][0] = 'INNER JOIN';
			$join_conds["ores_${type}_cls"][0] = 'INNER JOIN';
		}
	}

}
