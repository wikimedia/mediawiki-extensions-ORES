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
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ORES\Hooks\Helpers;
use OutputPage;
use RecentChange;
use RequestContext;
use Skin;

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
	 * Add CSS styles to output page
	 *
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		if ( !Helpers::oresUiEnabled() ) {
			return;
		}

		$oresData = $out->getProperty( 'oresData' );

		if ( $oresData !== null ) {
			$out->addJsConfigVars( 'oresData', $oresData );
			$out->addJsConfigVars(
				'oresThresholds',
				[ 'damaging' => Helpers::getDamagingThresholds() ]
			);
			$out->addModuleStyles( 'ext.ores.styles' );
			if ( Helpers::isHighlightEnabled( $out ) ) {
				$out->addModules( 'ext.ores.highlighter' );
			}
		}
	}

}
