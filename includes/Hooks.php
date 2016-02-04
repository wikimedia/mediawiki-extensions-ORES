<?php

namespace ORES;

use ChangesList;
use ChangesListSpecialPage;
use DatabaseUpdater;
use EnhancedChangesList;
use FormOptions;
use Html;
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
		if ( $rc->getAttribute( 'rc_type' ) === RC_EDIT ) {
			$logger = LoggerFactory::getInstance( 'ORES' );
			$logger->debug( 'Processing edit' );
			$job = new FetchScoreJob( $rc->getTitle(), array(
				'revid' => $rc->getAttribute( 'rc_this_oldid' ),
			) );
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
	 */
	public static function onChangesListSpecialPageFilters( ChangesListSpecialPage $clsp, &$filters ) {
		$filters['hidenondamaging'] = array(
			'msg' => 'ores-damaging-filter',
			'default' => false,
		);

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
	 */
	public static function onChangesListSpecialPageQuery(
		$name, array &$tables, array &$fields, array &$conds,
		array &$query_options, array &$join_conds, FormOptions $opts
	) {
		$threshold = self::getThreshold();

		$tables[] = 'ores_classification';
		$tables[] = 'ores_model';

		$fields[] = 'oresc_probability';
		$join_conds['ores_classification'] = array( 'LEFT JOIN',
			'rc_this_oldid = oresc_rev ' .
			'AND oresc_is_predicted = 1 AND oresc_class = 1' );

		// Add user-based threshold
		$fields[] = $threshold . ' AS ores_threshold';

		$join_conds['ores_model'] = array( 'LEFT JOIN',
			'oresc_model = oresm_id AND oresm_name = \'damaging\' ' .
			'AND oresm_is_current = 1'
		);

		if ( $opts->getValue( 'hidenondamaging' ) ) {
			// Filter out non-damaging edits.
			$conds[] = 'ores_is_predicted = 1';
			$conds[] = 'ores_probability > '
				. wfGetDb( DB_SLAVE )->addQuotes( $threshold );
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
	 */
	public static function onEnhancedChangesListModifyLineData( EnhancedChangesList $ecl, array &$data,
		array $block, RCCacheEntry $rcObj
	) {
		self::processRecentChangesList( $rcObj, $data );

		return true;
	}

	/**
	 * Label recent changes with ORES scores (for top-level ungrouped lines)
	 *
	 * @param EnhancedChangesList $ecl
	 * @param array $data
	 * @param RCCacheEntry $rcObj
	 */
	public static function onEnhancedChangesListModifyBlockLineData( EnhancedChangesList $ecl,
		array &$data, RCCacheEntry $rcObj
	) {

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
		$rc, &$classes = array()
	) {
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

		$options = array();
		foreach ( $wgOresDamagingThresholds as $case => $value ) {
			$text = wfMessage( 'ores-damaging-' . $case )->parse();
			$options[$text] = $case;
		}
		$preferences['oresDamagingPref'] = array(
			'type' => 'select',
			'label-message' => 'ores-pref-damaging',
			'section' => 'rc/ores',
			'options' => $options,
			'help-message' => 'ores-help-damaging-pref',
		);
		return true;
	}

	/**
	 * Add CSS styles to output page
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		$out->addModuleStyles( 'ext.ores.styles' );
		return true;
	}
}
