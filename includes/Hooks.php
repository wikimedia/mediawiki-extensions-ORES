<?php

namespace ORES;

use ChangesListSpecialPage;
use DatabaseUpdater;
use EnhancedChangesList;
use FormOptions;
use Html;
use JobQueueGroup;
use OldChangesList;
use RCCacheEntry;
use RecentChange;

/**
 * TODO:
 * - Fix mw-core EnhancedChangesList::recentChangesBlockGroup to rollup
 * extension recentChangesFlags into the top-level grouped line.
 * - Fix mw-core "old" recent changes view to respect recentChangesFlags.
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
			$job = new FetchScoreJob( $rc->getTitle(), array(
				'revid' => $rc->getAttribute( 'rc_this_oldid' ),
			) );
			JobQueueGroup::singleton()->push( $job );
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
		$filters['hidereverted'] = array(
			'msg' => 'ores-reverted-filter',
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
		if ( !$opts->getValue( 'hidereverted' ) ) {
			$tables[] = 'ores_classification';
			$fields[] = 'ores_probability';
			$join_conds['ores_classification'] = array( 'LEFT JOIN',
				'rc_this_oldid = ores_rev AND ores_model = \'reverted\' ' .
				'AND ores_is_predicted = 1 AND ores_class = \'true\'' );
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
		$score = $rcObj->getAttribute( 'ores_probability' );
		if ( $score !== null ) {
			$type = self::getRevertThreshold( $score );

			if ( $type ) {
				$data[] = self::getScoreHtml( $type, $score );

				$ecl->getOutput()->addModuleStyles( 'ext.ores.styles' );
			}
		}

		return true;
	}

	// FIXME: Repeated code.
	public static function onOldChangesListRecentChangesLine( OldChangesList &$ocl, &$html,
		RecentChange $rc, array &$classes
	) {
		$score = $rc->getAttribute( 'ores_probability' );
		if ( $score !== null ) {
			$type = self::getRevertThreshold( $score );

			if ( $type ) {
				$html = $html . ' ' . self::getScoreHtml( $type, $score );

				$ocl->getOutput()->addModuleStyles( 'ext.ores.styles' );
			}
		}

		return true;
	}

	protected static function getRevertThreshold( $score ) {
		global $wgOresRevertTagThresholds;

		$score = floatval( $score );
		$type = null;
		// TODO: Need to ensure the thresholds are ordered.
		foreach ( $wgOresRevertTagThresholds as $name => $value ) {
			if ( $score >= $value ) {
				$type = $name;
			}
		}
		return $type;
	}

	protected static function getScoreHtml( $type, $score ) {
		$cssClass = 'mw-ores-' . $type;
		$rounded = intval( 100 * $score );
		$msg = wfMessage( 'ores-reverted-' . $type )->numParams( $rounded )->escaped();
		$html = Html::rawElement( 'span', array(
			'title' => $msg,
			'class' => array( $cssClass, 'mw-ores-score' ),
		), $msg );

		return $html;
	}
}
