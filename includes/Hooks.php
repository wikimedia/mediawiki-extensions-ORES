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

namespace ORES;

use DatabaseUpdater;
use Exception;
use JobQueueGroup;
use IContextSource;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use OutputPage;
use RecentChange;
use RequestContext;
use Skin;
use SpecialRecentChanges;
use SpecialWatchlist;
use User;
use Title;

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
		$updater->addExtensionIndex( 'ores_classification', 'oresc_model_class_prob',
			__DIR__ . '/../sql/patch-ores-classification-model-class-prob-index.sql' );
		$updater->dropExtensionIndex( 'ores_classification', 'oresc_rev',
			__DIR__ . '/../sql/patch-ores-classification-indexes-part-ii.sql' );
	}

	/**
	 * Ask the ORES server for scores on this recent change
	 *
	 * @param RecentChange $rc
	 */
	public static function onRecentChange_save( RecentChange $rc ) {
		global $wgOresExcludeBots, $wgOresEnabledNamespaces, $wgOresModels, $wgOresDraftQualityNS;
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
		$models = array_keys( array_filter( $wgOresModels ) );
		if ( $rc_type === RC_EDIT || $rc_type === RC_NEW ) {
			// Do not store draftquality data when it's not a new page in article or draft ns
			if ( $rc_type !== RC_NEW ||
				!( isset( $wgOresDraftQualityNS[$ns] ) && $wgOresDraftQualityNS[$ns] )
			) {
				$models = array_diff( $models, [ 'draftquality' ] );
			}

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
				'models' => $models,
				'precache' => true,
			] );
			JobQueueGroup::singleton()->push( $job );
			$logger->debug( 'Job pushed for {revid}', [
				'revid' => $revid,
			] );
		}
	}

	/**
	 * Remove cached scores for revisions which were purged from recentchanges
	 *
	 * @param \stdClass[] $rows
	 */
	public static function onRecentChangesPurgeRows( array $rows ) {
		$revIds = [];
		foreach ( $rows as $row ) {
			$revIds[] = $row->rc_this_oldid;
		}
		MediaWikiServices::getInstance()->getService( 'ORESScoreStorage' )->purgeRows( $revIds );
	}

	/**
	 * Internal helper to get damaging level preference
	 * with backward compatibility for old level names
	 * @param User $user
	 * @param Title $title
	 * @return string 'maybebad', 'likelybad', or 'verylikelybad'
	 */
	public static function getDamagingLevelPreference( User $user, Title $title = null ) {
		$option = !$title || self::isWLPage( $title ) ?
				'oresDamagingPref' :
				'rcOresDamagingPref';

		$pref = $user->getOption( $option );
		if ( isset( self::$damagingPrefMap[ $pref ] ) ) {
			$pref = self::$damagingPrefMap[ $pref ];
		}
		return $pref;
	}

	/**
	 * Internal helper to get threshold
	 * @param string $type
	 * @param User $user
	 * @param Title|null $title
	 * @return float|null Threshold, or null if not set
	 * @throws Exception When $type is not recognized
	 */
	public static function getThreshold( $type, User $user, Title $title = null ) {
		if ( $type === 'damaging' ) {
			$pref = self::getDamagingLevelPreference( $user, $title );
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
		$stats = MediaWikiServices::getInstance()->getService( 'ORESThresholdLookup' );
		$thresholds = [];
		foreach ( $stats->getThresholds( 'damaging' ) as $name => $bounds ) {
			$thresholds[ $name ] = $bounds[ 'min' ];
		}
		unset( $thresholds[ 'likelygood' ] );
		return $thresholds;
	}

	/**
	 * Check whether ores is enabled
	 *
	 * @param User $user
	 * @return bool
	 */
	public static function oresUiEnabled( User $user ) {
		global $wgOresUiEnabled;

		// Is the UI enabled or not?  If not, we've been deployed in
		// infrastructure-only mode, so hide all the UI elements.
		if ( !$wgOresUiEnabled ) {
			return false;
		}

		return true;
	}

	/**
	 * @param Title $title
	 * @return bool Whether $title is a RecentChanges page
	 */
	private static function isRCPage( Title $title ) {
		return $title->isSpecial( 'Recentchanges' ) ||
			$title->isSpecial( 'Recentchangeslinked' );
	}

	/**
	 * @param Title $title
	 * @return bool Whether $title is the Watchlist page
	 */
	private static function isWLPage( Title $title ) {
		return $title->isSpecial( 'Watchlist' );
	}

	public static function isRCStructuredUiEnabled( IContextSource $context ) {
		$page = new SpecialRecentChanges();
		$page->setContext( $context );
		return $page->isStructuredFilterUiEnabled();
	}

	public static function isWLStructuredUiEnabled( IContextSource $context ) {
		$page = new SpecialWatchlist();
		$page->setContext( $context );
		return $page->isStructuredFilterUiEnabled();
	}

	/**
	 * @param IContextSource $context
	 * @return bool Whether highlights should be shown
	 */
	public static function isHighlightEnabled( IContextSource $context ) {
		// Was previously controlled by different preferences than the "r", but they're currently
		// the same.
		return self::isDamagingFlagEnabled( $context );
	}

	/**
	 * @param IContextSource $context
	 * @return bool Whether the damaging flag ("r") should be shown
	 */
	public static function isDamagingFlagEnabled( IContextSource $context ) {
		$user = $context->getUser();

		if ( !self::oresUiEnabled( $user ) ) {
			return false;
		}

		if ( self::isRCPage( $context->getTitle() ) ) {
			return !self::isRCStructuredUiEnabled( $context ) &&
				$user->getBoolOption( 'ores-damaging-flag-rc' );
		}

		if ( self::isWLPage( $context->getTitle() ) ) {
			return !self::isWLStructuredUiEnabled( $context ) &&
				$user->getBoolOption( 'oresHighlight' );
		}

		return $user->getBoolOption( 'oresHighlight' );
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
	public static function addRowData( IContextSource $context, $revisionId, $score, $model ) {
		$out = $context->getOutput();
		$data = $out->getProperty( 'oresData' );
		if ( !isset( $data[$revisionId] ) ) {
			$data[$revisionId] = [];
		}
		$data[$revisionId][$model] = $score;
		$out->setProperty( 'oresData', $data );
	}

	public static function joinWithOresTables(
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

		$modelId = MediaWikiServices::getInstance()->getService( 'ORESModelLookup' )->getModelId(
			$type
		);
		$tables["ores_${type}_cls"] = 'ores_classification';

		$fields["ores_${type}_score"] = "ores_${type}_cls.oresc_probability";

		$join_conds["ores_${type}_cls"] = [ 'LEFT JOIN', [
			"ores_${type}_cls.oresc_model" => $modelId,
			"ores_${type}_cls.oresc_rev=$revIdField",
			"ores_${type}_cls.oresc_class" => 1
		] ];
	}

	public static function hideNonDamagingFilter(
		array &$fields,
		array &$conds,
		$hidenondamaging,
		User $user,
		Title $title = null
	) {
		$dbr = \wfGetDB( DB_REPLICA );
		// Add user-based threshold
		$threshold = self::getThreshold( 'damaging', $user, $title );
		if ( $threshold === null ) {
			return;
		}
		// FIXME: This is not a "filter" but an undocumented side effect of this function.
		$fields['ores_damaging_threshold'] = $threshold;

		if ( $hidenondamaging ) {
			// Filter out non-damaging edits.
			$conds[] = 'ores_damaging_cls.oresc_probability > ' . $dbr->addQuotes( $threshold );
		}
	}

}
