<?php

namespace ORES;

use BetaFeatures;
use ChangesList;
use ChangesListSpecialPage;
use DatabaseUpdater;
use EnhancedChangesList;
use FormOptions;
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use OutputPage;
use RCCacheEntry;
use RecentChange;
use Skin;
use User;

class Hooks {
	/**
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'ores_classification', __DIR__ . '/../sql/ores_classification.sql' );
		$updater->addExtensionTable( 'ores_model', __DIR__ . '/../sql/ores_model.sql' );

		return true;
	}

	/**
	 * Ask the ORES server for scores on this recent change
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
				$wgOresEnabledNamespaces[$ns] ) ) {
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
	public static function onChangesListSpecialPageFilters( ChangesListSpecialPage $clsp, &$filters ) {
		if ( self::oresEnabled( $clsp->getUser() ) === false ) {
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
	 * @param $name
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
		if ( self::oresEnabled( $wgUser ) === false ) {
			return true;
		}

		$threshold = self::getThreshold();
		$dbr = \wfGetDB( DB_SLAVE );

		$tables[] = 'ores_classification';
		$tables[] = 'ores_model';

		$fields[] = 'oresc_probability';
		// Add user-based threshold
		$fields[] = $dbr->addQuotes( $threshold ) . ' AS ores_threshold';

		$conds[] = '(oresm_name = ' . $dbr->addQuotes( 'damaging' ) .
			' OR oresm_name IS NULL)';

		$join_conds['ores_classification'] = [ 'LEFT JOIN',
			'rc_this_oldid = oresc_rev ' .
			'AND oresc_class = 1' ];

		$join_conds['ores_model'] = [ 'LEFT JOIN',
			'oresc_model = oresm_id ' .
			'AND oresm_is_current = 1'
		];

		if ( $opts->getValue( 'hidenondamaging' ) ) {
			// Override the join conditions.
			$join_conds['ores_classification'] = [ 'INNER JOIN',
				'rc_this_oldid = oresc_rev ' .
				'AND oresc_class = 1' ];

			// Filter out non-damaging edits.
			$conds[] = 'oresc_probability > '
				. $dbr->addQuotes( $threshold );
			$conds['rc_patrolled'] = 0;
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
	 * @return bool
	 */
	public static function onEnhancedChangesListModifyLineData( EnhancedChangesList $ecl, array &$data,
		array $block, RCCacheEntry $rcObj, array &$classes
	) {
		if ( self::oresEnabled( $ecl->getUser() ) === false ) {
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
	public static function onEnhancedChangesListModifyBlockLineData( EnhancedChangesList $ecl,
		array &$data, RCCacheEntry $rcObj
	) {
		if ( self::oresEnabled( $ecl->getUser() ) === false ) {
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
	public static function onOldChangesListRecentChangesLine( ChangesList &$changesList, &$s,
		$rc, &$classes = []
	) {
		if ( self::oresEnabled( $changesList->getUser() ) === false ) {
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
			$parts[1] = $changesList->flag( 'damaging' ) . $parts[1];
			$s = implode( $separator, $parts );
		}

		return true;
	}

	/**
	 * Internal helper to label matching rows
	 */
	protected static function processRecentChangesList( RCCacheEntry $rcObj,
		array &$data, array &$classes = []
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
	protected static function getScoreRecentChangesList( $rcObj ) {

		$threshold = $rcObj->getAttribute( 'ores_threshold' );
		if ( $threshold === null ) {
			// FIXME: What is the impact of this
			$logger = LoggerFactory::getInstance( 'ORES' );
			$logger->warning( 'Running low performance actions, ' .
				'getting threshold for each edit seperately' );
			$threshold = self::getThreshold();
		}
		$score = $rcObj->getAttribute( 'oresc_probability' );
		$patrolled = $rcObj->getAttribute( 'rc_patrolled' );

		return $score && $score >= $threshold && !$patrolled;
	}

	/**
	 * Internal helper to get threshold
	 */
	protected static function getThreshold() {
		global $wgOresDamagingThresholds;
		global $wgUser;

		$pref = $wgUser->getOption( 'oresDamagingPref' );

		return $wgOresDamagingThresholds[$pref];
	}

	/**
	 * GetPreferences hook, adding ORES section, letting people choose a threshold
	 * Also let people make hidenondamaging default
	 */
	public static function onGetPreferences( $user, &$preferences ) {
		global $wgOresDamagingThresholds;

		if ( self::oresEnabled( $user ) === false ) {
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
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		if ( self::oresEnabled( $out->getUser() ) === false ) {
			return true;
		}
		$out->addModuleStyles( 'ext.ores.styles' );
		return true;
	}

	/**
	 * Make a beta feature
	 */
	public static function onGetBetaFeaturePreferences( $user, &$prefs ) {
		global $wgExtensionAssetsPath;

		$prefs['ores-enabled'] = [
			'label-message' => 'ores-beta-feature-message',
			'desc-message' => 'ores-beta-feature-description',
			'screenshot' => [
				'ltr' => "$wgExtensionAssetsPath/ORES/images/ORES-beta-features-ltr.png",
				'rtl' => "$wgExtensionAssetsPath/ORES/images/ORES-beta-features-rtl.png",
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
		if ( BetaFeatures::isFeatureEnabled( $user, 'ores-enabled' ) ) {
			return true;
		}
		return false;
	}
}
