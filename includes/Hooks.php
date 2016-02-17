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

/**
 * TODO:
 * - Fix mw-core EnhancedChangesList::recentChangesBlockGroup to rollup
 * extension recentChangesFlags into the top-level grouped line.
 */
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
		global $wgOresExcludeBots;
		if ( $rc->getAttribute( 'rc_bot' ) && $wgOresExcludeBots ) {
			return true;
		}

		if ( $rc->getAttribute( 'rc_type' ) === RC_EDIT ) {
			$logger = LoggerFactory::getInstance( 'ORES' );
			$logger->debug( 'Processing edit' );
			$job = new FetchScoreJob( $rc->getTitle(), [
				'revid' => $rc->getAttribute( 'rc_this_oldid' ),
			] );
			JobQueueGroup::singleton()->push( $job );
			$logger->debug( 'Job pushed...' );
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

		$filters['hidenondamaging'] = [
			'msg' => 'ores-damaging-filter',
			'default' => false,
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
		if ( self::oresEnabled() === false ) {
			return true;
		}

		$threshold = self::getThreshold();

		$tables[] = 'ores_classification';
		$tables[] = 'ores_model';

		$fields[] = 'oresc_probability';
		$join_conds['ores_classification'] = [ 'LEFT JOIN',
			'rc_this_oldid = oresc_rev ' .
			'AND oresc_is_predicted = 1 AND oresc_class = 1' ];

		// Add user-based threshold
		$fields[] = $threshold . ' AS ores_threshold';

		$join_conds['ores_model'] = [ 'LEFT JOIN',
			'oresc_model = oresm_id AND oresm_name = \'damaging\' ' .
			'AND oresm_is_current = 1'
		];

		if ( $opts->getValue( 'hidenondamaging' ) ) {
			// Filter out non-damaging edits.
			$conds[] = 'ores_is_predicted = 1';
			$conds[] = 'ores_probability > '
				. \wfGetDB( DB_SLAVE )->addQuotes( $threshold );
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
		array $block, RCCacheEntry $rcObj
	) {
		if ( self::oresEnabled( $ecl->getUser() ) === false ) {
			return true;
		}

		self::processRecentChangesList( $rcObj, $data );

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
	protected static function processRecentChangesList( RCCacheEntry $rcObj, array &$data ) {
		$damaging = self::getScoreRecentChangesList( $rcObj );
		if ( $damaging ) {
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
			$logger = LoggerFactory::getInstance( 'ORES' );
			$logger->debug( 'WARNING: Running low perofrmance actions, ' .
				'getting threshold for each edit seperately' );
			$threshold = self::getThreshold();
		}
		$score = $rcObj->getAttribute( 'oresc_probability' );
		$patrolled = $rcObj->getAttribute( 'rc_patrolled' );
		if ( $score && $score >= $threshold && !$patrolled ) {
			return true;
		} else {
			return false;
		}
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
			'info-link' => 'https://www.mediawiki.org/wiki/Extension:ORES',
			'discussion-link' => 'https://www.mediawiki.org/wiki/Extension_talk:ORES',
		];
	}

	/**
	 * Check whether the user enabled ores as a beta feature
	 *
	 * @param \User $user
	 * @return bool
	 */
	public static function oresEnabled( $user = null ) {
		if ( $user === null ) {
			global $wgUser;
			$user = $wgUser;
		}
		if ( BetaFeatures::isFeatureEnabled( $user, 'ores-enabled' ) ) {
			return true;
		}
		return false;
	}
}
